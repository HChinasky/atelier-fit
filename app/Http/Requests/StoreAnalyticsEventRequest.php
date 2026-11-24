<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a first-party UI analytics event (Этап A3). Only interactions that
 * exist purely in the browser come through here (a tap on a card, the share
 * sheet, the shop button) — everything the server can see itself is captured
 * server-side and this endpoint will not accept it.
 *
 * Strict allow-list twice over: the event name must be one of the known UI
 * events, and each event accepts ONLY its own declared properties with typed,
 * bounded values — free-form strings never pass, so the endpoint cannot be
 * used to inject junk or PII into the analytics stream.
 */
class StoreAnalyticsEventRequest extends FormRequest
{
    /** The client-side events and the properties each may carry. */
    public const EVENTS = [
        'item_selected' => ['product_id'],
        'result_viewed' => ['mode', 'time_to_view_ms'],
        'result_shared' => ['format', 'method', 'channel'],
        'shop_clicked' => ['product_id'],
        'consult_clicked' => ['looks_generated_count'],
        'regenerate_clicked' => ['reason'],
    ];

    public function authorize(): bool
    {
        return true; // public page, pseudonymous events only
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'event' => ['required', 'string', Rule::in(array_keys(self::EVENTS))],
            'props' => ['sometimes', 'array'],
            'props.product_id' => ['sometimes', 'integer', 'exists:products,id'],
            'props.mode' => ['sometimes', Rule::in(['single', 'outfit'])],
            'props.time_to_view_ms' => ['sometimes', 'integer', 'min:0', 'max:3600000'],
            'props.format' => ['sometimes', Rule::in(['feed', 'story'])],
            'props.method' => ['sometimes', Rule::in(['web_share', 'download', 'copy_link'])],
            'props.channel' => ['sometimes', 'nullable', Rule::in(['whatsapp', 'instagram', 'other'])],
            'props.looks_generated_count' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'props.reason' => ['sometimes', 'nullable', Rule::in(['new_photo', 'new_item'])],
        ];
    }

    /**
     * The validated properties narrowed to THIS event's allow-list — a valid
     * key sent with the wrong event is silently dropped.
     *
     * @return array<string, mixed>
     */
    public function eventProps(): array
    {
        $event = (string) $this->validated('event');
        $props = (array) $this->validated('props', []);

        return array_intersect_key($props, array_flip(self::EVENTS[$event] ?? []));
    }
}
