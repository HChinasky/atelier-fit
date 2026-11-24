<?php

namespace App\Services\Pose;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Talks to the self-hosted pose microservice (POST /pose). The photo is sent as
 * a multipart upload (field "image"); when a token is configured it is passed in
 * the X-API-Token header. All tunables (url, token, timeout) come from
 * config('services.pose'); nothing is hardcoded.
 *
 * No private data ever appears in logs or thrown messages: we log only the fact
 * of a failure and safe metadata (HTTP status), never the photo bytes or filename
 * contents.
 */
class MediaPipePoseDetector implements PoseDetector
{
    public function detect(string $imageContents, string $filename = 'photo.jpg'): PoseResult
    {
        try {
            $response = $this->client()
                ->attach('image', $imageContents, $filename)
                ->post('/pose');
        } catch (Throwable $e) {
            // Network/transport failure or timeout. Log metadata only — never the
            // image. Callers apply PoseFailureMode (fail-open/closed) in 9.2/9.5.
            Log::warning('[pose] detection request failed: transport error');

            throw new PoseDetectionException('Pose detection request failed: transport error');
        }

        if ($response->failed()) {
            Log::warning('[pose] detection rejected', ['status' => $response->status()]);

            throw new PoseDetectionException('Pose detection rejected with status '.$response->status());
        }

        return $this->parse($response->json());
    }

    /**
     * Map the service's JSON body into a typed PoseResult, strictly validating
     * the contract. Fields that 9.2 (validation) and 9.4 (cropping) depend on
     * are REQUIRED with the right type — a missing/wrong field fails loudly
     * instead of silently becoming 0/null, which would cause hidden wrong
     * decisions downstream. Extra/unknown fields are ignored.
     *
     * @throws PoseDetectionException on a missing/invalid required field.
     */
    private function parse(mixed $data): PoseResult
    {
        if (! is_array($data)) {
            $this->fail('non-object response');
        }

        // Required top-level fields.
        $personCount = $this->requireNonNegativeInt($data, 'person_count');

        $dimensions = $data['image_dimensions'] ?? null;
        if (! is_array($dimensions)) {
            $this->fail('missing image_dimensions');
        }
        $width = $this->requirePositiveInt($dimensions, 'image_dimensions.width');
        $height = $this->requirePositiveInt($dimensions, 'image_dimensions.height');

        $threshold = $this->requireNumber($data, 'bbox_confidence_threshold');

        // main_person is required iff a person was detected; for person_count 0 it
        // may be null (and any stray value is ignored).
        $mainPerson = null;
        if ($personCount >= 1) {
            $person = $data['main_person'] ?? null;
            if (! is_array($person) || $person === []) {
                $this->fail('missing main_person for person_count >= 1');
            }
            $mainPerson = $this->parsePerson($person);
        }

        return new PoseResult(
            personCount: $personCount,
            imageWidth: $width,
            imageHeight: $height,
            mainPerson: $mainPerson,
            bboxConfidenceThreshold: $threshold,
        );
    }

    /**
     * Strictly parse the main person: a bounding box (x,y,width,height) and a
     * non-empty set of landmarks each carrying a name, coordinates and
     * confidence. Optional per-landmark fields (x_norm, y_norm, z) default when
     * absent; the required ones do not.
     *
     * @param  array<mixed>  $person
     *
     * @throws PoseDetectionException on a missing/invalid bounding box or landmarks.
     */
    private function parsePerson(array $person): Person
    {
        $box = $person['bounding_box'] ?? null;
        if (! is_array($box)) {
            $this->fail('main_person missing bounding_box');
        }
        $boundingBox = new BoundingBox(
            x: $this->requireNumber($box, 'bounding_box.x'),
            y: $this->requireNumber($box, 'bounding_box.y'),
            width: $this->requireNumber($box, 'bounding_box.width'),
            height: $this->requireNumber($box, 'bounding_box.height'),
        );

        $rawLandmarks = $person['landmarks'] ?? null;
        if (! is_array($rawLandmarks) || $rawLandmarks === []) {
            $this->fail('main_person has no landmarks');
        }

        $landmarks = [];
        foreach ($rawLandmarks as $lm) {
            if (! is_array($lm)) {
                $this->fail('malformed landmark entry');
            }
            $name = $lm['name'] ?? null;
            if (! is_string($name) || $name === '') {
                $this->fail('landmark without a name');
            }
            $landmarks[$name] = new Landmark(
                name: $name,
                x: $this->requireNumber($lm, "landmark.{$name}.x"),
                y: $this->requireNumber($lm, "landmark.{$name}.y"),
                xNorm: $this->optionalNumber($lm, 'x_norm'),
                yNorm: $this->optionalNumber($lm, 'y_norm'),
                z: $this->optionalNumber($lm, 'z'),
                confidence: $this->requireNumber($lm, "landmark.{$name}.confidence"),
            );
        }

        return new Person(boundingBox: $boundingBox, landmarks: $landmarks);
    }

    /**
     * Fail the contract with a clear, photo-free message (also logged as such).
     */
    private function fail(string $what): never
    {
        $message = 'Pose service returned an invalid response: '.$what;
        Log::warning('[pose] '.$message);

        throw new PoseDetectionException($message);
    }

    /**
     * @param  array<mixed>  $data
     */
    private function requireNumber(array $data, string $key): float
    {
        $value = $data[$this->leaf($key)] ?? null;
        if (! is_int($value) && ! is_float($value)) {
            $this->fail("field {$key} must be a number");
        }

        return (float) $value;
    }

    /**
     * @param  array<mixed>  $data
     */
    private function requirePositiveInt(array $data, string $key): int
    {
        $value = $data[$this->leaf($key)] ?? null;
        if (! is_int($value) || $value <= 0) {
            $this->fail("field {$key} must be a positive integer");
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $data
     */
    private function requireNonNegativeInt(array $data, string $key): int
    {
        $value = $data[$this->leaf($key)] ?? null;
        if (! is_int($value) || $value < 0) {
            $this->fail("field {$key} must be a non-negative integer");
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $data
     */
    private function optionalNumber(array $data, string $key): float
    {
        $value = $data[$key] ?? null;

        return (is_int($value) || is_float($value)) ? (float) $value : 0.0;
    }

    /**
     * The leaf key of a dotted path used only for human-readable messages
     * (e.g. "image_dimensions.width" -> "width"), so callers can pass the full
     * path for the error text while we index the local array.
     */
    private function leaf(string $key): string
    {
        $parts = explode('.', $key);

        return end($parts);
    }

    /**
     * A JSON-accepting client pinned to the pose service base URL, with the
     * optional X-API-Token header and a bounded timeout.
     */
    private function client(): PendingRequest
    {
        $request = Http::baseUrl((string) config('services.pose.url'))
            ->acceptJson()
            ->timeout((int) config('services.pose.timeout', 15));

        $token = (string) config('services.pose.token');
        if ($token !== '') {
            $request = $request->withHeaders(['X-API-Token' => $token]);
        }

        return $request;
    }
}
