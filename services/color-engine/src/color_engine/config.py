"""Runtime configuration.

Every threshold the pipeline uses lives here, with a comment saying what the
number means. No magic constants in the step modules - if a step needs a number,
it reads it off ``EngineConfig``.

All values are overridable through the environment with the ``COLOR_ENGINE_``
prefix, e.g. ``COLOR_ENGINE_MAX_SIDE=2048``.
"""

from __future__ import annotations

from pathlib import Path

from pydantic import Field
from pydantic_settings import BaseSettings, SettingsConfigDict

# services/color-engine/ - models/ sits next to pyproject.toml.
PACKAGE_ROOT = Path(__file__).resolve().parent
SERVICE_ROOT = PACKAGE_ROOT.parent.parent

FACE_LANDMARKER_FILENAME = "face_landmarker.task"
SELFIE_SEGMENTER_FILENAME = "selfie_multiclass_256x256.tflite"


class EngineConfig(BaseSettings):
    """Immutable-ish settings object. Build one per process and hand it to ``Engine``."""

    model_config = SettingsConfigDict(
        env_prefix="COLOR_ENGINE_",
        extra="ignore",
        protected_namespaces=(),
    )

    # --- model artifacts ----------------------------------------------------

    models_dir: Path = Field(
        default=SERVICE_ROOT / "models",
        description="Directory holding the MediaPipe .task/.tflite artifacts.",
    )

    # --- input limits (io.py) ----------------------------------------------

    max_input_bytes: int = Field(
        default=15 * 1024 * 1024,
        description="Reject the upload before decoding above this many bytes (15 MiB).",
    )
    max_side: int = Field(
        default=1600,
        description="Longest side after decoding. Bigger photos are downscaled (Lanczos); "
        "beyond this, detection gains nothing and costs time.",
    )
    min_short_side: int = Field(
        default=360,
        description="Shortest side below which a photo is rejected as low_resolution. 360, not "
        "the original 480: members send messenger-compressed photos, and refusing them outright "
        "helped nobody - a borderline photo now reads with lowered confidence instead.",
    )

    # --- face detection (detect.py) ----------------------------------------

    max_faces: int = Field(
        default=2,
        description="Detect up to this many faces. 2 is enough to tell 'one face' from 'a crowd'.",
    )
    min_face_detection_confidence: float = Field(default=0.5, ge=0.0, le=1.0)
    min_face_presence_confidence: float = Field(default=0.5, ge=0.0, le=1.0)
    min_face_tracking_confidence: float = Field(default=0.5, ge=0.0, le=1.0)

    min_face_frame_ratio: float = Field(
        default=0.10,
        gt=0.0,
        le=1.0,
        description="Face bounding box must cover at least this fraction of the frame, measured "
        "as max(face_w/img_w, face_h/img_h). Below it there are too few skin pixels to trust.",
    )

    # --- absolute size floors, measured at SOURCE resolution ----------------
    # The ratio above is not enough. A 640x480 photo of a face filling the frame
    # passes it while carrying a fraction of the detail of the same composition
    # shot on a phone, and what the measurement actually depends on is how many
    # real sensor pixels landed on the skin and the eye.
    #
    # Measured before any downscaling, and there is never any upscaling: these
    # are counts of pixels the camera really captured. Both PRELIMINARY.

    min_face_width_px: int = Field(
        default=160,
        gt=0,
        description="Face bounding-box width in the ORIGINAL image, in pixels. Below it the face "
        "carries too little real detail whatever share of the frame it fills -> face_too_small.",
    )
    min_iris_diameter_px: float = Field(
        default=20.0,
        gt=0.0,
        description="Iris diameter in the ORIGINAL image, in pixels. The iris is about a twelfth "
        "of the face's width, so it runs out of pixels long before the cheeks do: below this the "
        "annulus is a handful of pixels and the sclera wedges beside it are thinner still. Not a "
        "rejection - the eye zones are reported insufficient with reason 'eye_too_small' and the "
        "skin analysis carries on.",
    )

    use_transformation_matrix: bool = Field(
        default=True,
        description="Ask MediaPipe for the facial transformation matrix and read head pose off "
        "it. When off (or when the matrix is missing) pose falls back to landmark asymmetry.",
    )
    max_yaw_deg: float = Field(
        default=22.0,
        description="Head turn left/right above this many degrees -> face_not_frontal.",
    )
    max_pitch_deg: float = Field(
        default=30.0,
        description="Head tilt up/down above this many degrees -> face_not_frontal. Looser than "
        "yaw because a selfie is usually held below the face: a photo measured at 22.4 degrees "
        "was refused on a 22 limit while being an ordinary, well-sized frontal selfie. PRELIMINARY "
        "like every threshold here.",
    )
    max_roll_deg: float = Field(
        default=25.0,
        description="In-plane head tilt. Looser than yaw: roll barely changes which skin we see.",
    )
    max_face_asymmetry: float = Field(
        default=0.18,
        description="Landmark fallback for yaw: |dL-dR|/(dL+dR) between the nose tip and the two "
        "face-oval sides. 0 is dead-on frontal, 1 is full profile.",
    )

    # --- zones (zones.py) ---------------------------------------------------

    zone_polygon_shrink: float = Field(
        default=0.85,
        gt=0.0,
        le=1.0,
        description="Cheek/forehead polygons are scaled toward their centroid by this factor, "
        "pulling the edges away from the brow line, nostril wing and jaw shadow.",
    )
    # The iris is measured on an ANNULUS between these two, as fractions of the
    # measured iris radius. The first live frame read L* 17.7 on light eyes,
    # because the median was taken over the pupil, the dark limbal ring and the
    # shadow of the upper lid rather than over the iris.
    iris_outer_ratio: float = Field(
        default=0.80,
        gt=0.0,
        le=1.0,
        description="Outer edge of the annulus. Inside the limbal ring - the dark band at the "
        "edge of every iris, which is not the eye's colour and is dark enough to drag a median "
        "a long way.",
    )
    iris_inner_ratio: float = Field(
        default=0.55,
        gt=0.0,
        lt=1.0,
        description="Inner edge, excluding the pupil with room for it to be DILATED: indoors a "
        "pupil is much wider than in daylight, and a ratio tuned on a bright photo puts black "
        "pixels into the sample of every dim one. PRELIMINARY.",
    )
    iris_opening_shrink: float = Field(
        default=0.88,
        gt=0.0,
        le=1.0,
        description="The eye opening is pulled toward its centroid by this factor before the "
        "annulus is clipped to it, so the lash line does not come with it. A scaled polygon "
        "rather than an eroded mask: an integer erosion kernel would make the margin a slightly "
        "different fraction of the eye at different resolutions, and this annulus is a thin ring "
        "sitting right at that boundary.",
    )
    iris_specular_percentile: float = Field(
        default=92.0,
        gt=50.0,
        lt=100.0,
        description="A catchlight sits above this L* percentile of the zone AND below its median "
        "chroma - it is a picture of the lamp, so it is bright and near-neutral while the iris "
        "around it is coloured. Relative, because a brown iris and a blue one share no absolute "
        "threshold.",
    )
    iris_specular_max_area: float = Field(
        default=0.20,
        gt=0.0,
        le=1.0,
        description="Only SMALL connected blobs are glints. The bright rim of a pale iris is a "
        "large connected region and must survive, or the measurement loses exactly the pixels "
        "that carry the colour.",
    )
    sclera_iris_margin: float = Field(
        default=0.20,
        ge=0.0,
        description="How far beyond the iris radius (as a fraction of it) the sclera wedges "
        "start, measured along the eye axis. Keeps the limbal ring out of the sample.",
    )

    # Morphology kernels are expressed as a fraction of the face bounding-box width, never in
    # raw pixels: the same photo at 800px and at 1600px must produce the same zones.
    skin_erode_ratio: float = Field(
        default=0.004,
        ge=0.0,
        description="Erosion of the skin mask (polygon AND face-skin class), in face widths.",
    )
    hair_erode_ratio: float = Field(
        default=0.020,
        ge=0.0,
        description="Erosion of the hair class. Generous: hair borders both skin and background, "
        "and both leak.",
    )
    hair_below_chin_ratio: float = Field(
        default=0.0,
        ge=0.0,
        description="How far below the chin, in face heights, the hair zone may reach. The "
        "segmenter labels a dark top or jacket under the hair as HAIR, and a frame measured that "
        "way came out at L* 25 for a light-brown head: the shirt in the hair mean, depth read as "
        "deep. Hair is measured where it is on the head; long hair hanging past the chin is "
        "ignored, which costs pixels and no information - the colour of the part by the face is the "
        "colour of the person. PRELIMINARY.",
    )
    # Accessories. The segmenter has a class for them - glasses frames,
    # jewellery, headphones, hairbands - and the hair mask runs right up to it,
    # so the band of the segmenter's own uncertainty sits ON the hair edge. A
    # frame with headphones measured hair at L* 12.2, C* 3.0: near-black and
    # near-neutral, which is the headphones, not the person.
    #
    # Both PRELIMINARY.
    accessory_dilate_ratio: float = Field(
        default=0.035,
        ge=0.0,
        description="Grow the accessory class by this fraction of the face width before taking it "
        "out of the hair. Wider than the hair erosion, because the boundary between hair and a "
        "dark plastic cup is exactly where a segmenter is least sure, and the pixels it gets wrong "
        "are dark and neutral - which is to say they look like hair and move the measurement.",
    )
    max_accessory_over_head: float = Field(
        default=0.45,
        gt=0.0,
        le=1.0,
        description="Share of the HEAD's visible surface - hair plus the accessory blobs touching "
        "it - that may be something being worn before the hair is reported occluded rather than "
        "measured from what is left. Over the head and not over the hair: a pixel belongs to one "
        "segmenter class, so hair under a headphone cup is classed as accessory and never counted "
        "as hair, which makes 'how much hair did we lose' come out small however much is covered.",
    )

    # How much of the way from the iris to each corner the wedges stop short.
    # Fractions of the distance from the iris CENTRE to that corner, measured
    # along the eye's own axis.
    #
    # The landmark opening runs corner to corner, but the white of the eye does
    # not: the caruncle - the pink fleshy mound - sits inside the inner corner,
    # and at the outer corner the two lid margins converge onto skin. Measured
    # on a synthetic eye, that corner skin is between a fifth and two thirds of
    # the wedge mask, and when it passes about 60% the a*b* filter inverts: it
    # takes its reference from the median of what it is given, so a majority of
    # skin makes SKIN the reference, and the filter then keeps the skin and
    # discards the sclera. That is a direct route to not_brighter_than_skin -
    # the sample really is not brighter than skin, because it IS skin.
    #
    # Both PRELIMINARY.
    sclera_inner_corner_cut: float = Field(
        default=0.35,
        ge=0.0,
        lt=1.0,
        description="Fraction of the iris-to-INNER-corner distance left out. The larger of the "
        "two: the caruncle is a structure with real size, not an edge effect.",
    )
    sclera_outer_corner_cut: float = Field(
        default=0.20,
        ge=0.0,
        lt=1.0,
        description="The same at the outer corner, where the lids converge onto skin and there is "
        "no separate structure to avoid - only the join itself.",
    )
    sclera_erode_ratio: float = Field(
        default=0.12,
        ge=0.0,
        description="Erosion of the sclera mask, as a fraction of the eye opening's HEIGHT - "
        "not of the face. A squint and a wide-open eye on the same face need different "
        "margins, and what has to be cleared is the lash line either way.",
    )

    min_zone_pixels: int = Field(
        default=200,
        description="Skin and hair zones below this many pixels are reported insufficient.",
    )
    min_small_zone_pixels: int = Field(
        default=40,
        description="Same, for iris and sclera - genuinely small zones even in a good photo.",
    )

    # --- sampling (sampling.py) --------------------------------------------

    l_percentile_low: float = Field(
        default=10.0,
        ge=0.0,
        lt=50.0,
        description="Drop pixels below this L* percentile inside a zone - shadows.",
    )
    l_percentile_high: float = Field(
        default=90.0,
        gt=50.0,
        le=100.0,
        description="Drop pixels above this L* percentile inside a zone - specular highlights.",
    )
    chroma_mad_k: float = Field(
        default=3.0,
        gt=0.0,
        description="Skin only: drop pixels whose C* is more than k robust sigmas (MAD*1.4826) "
        "from the zone median. Kills stray lipstick, blemishes, hair strands.",
    )
    chroma_min_spread: float = Field(
        default=1.0,
        ge=0.0,
        description="Floor on the chroma tolerance, in C* units. A near-flat zone has a MAD of "
        "zero, and without this floor the rule above would degenerate into keeping everything.",
    )
    # --- sclera pixel selection --------------------------------------------
    # The sclera is the one zone whose signal is the *bright* end of its own
    # distribution, so the generic percentile trim above is wrong for it: that
    # would throw away the white of the eye and keep the lash line. These
    # thresholds replace it.
    #
    # Lightness does the separating, and it is the only thing that can. A
    # chroma cap cannot: under a tungsten bulb a healthy sclera measures C* 60
    # while the dark lid beside it measures C* 25, because CIELAB compresses
    # chroma at low lightness. Whichever way that cap pointed it would be wrong
    # under some light. What is true under every light is that the sclera is
    # the brightest thing inside the eye opening.
    #
    # Colour is then used only as a *distance*: once the bright pixels are
    # picked, their median a*b* is the sclera under the light of the moment,
    # and a pixel far from it in a*b* is eyeliner, the caruncle or a lash that
    # survived - whatever the light is doing to all of them equally.

    sclera_l_percentile_low: float = Field(
        default=55.0,
        ge=0.0,
        lt=100.0,
        description="Keep only the upper part of the zone's L* distribution. The darker half of "
        "any sclera mask is lid, lash or the shadow they cast - never the white of the eye.",
    )
    sclera_max_ab_distance: float = Field(
        default=15.0,
        gt=0.0,
        description="Euclidean distance in a*b* from the bright set's median beyond which a pixel "
        "is not sclera: eyeliner, the caruncle, a lash edge. Relative to the zone's own colour, "
        "so it means the same thing under a window and under a bulb.",
    )

    sclera_specular_l: float = Field(
        default=96.0,
        description="L* above which a pixel is a candidate catchlight. A glint is a picture of "
        "the light source, not of the eye, and it would drag the sample toward the lamp. Set "
        "high on purpose: a well-exposed sclera peaks around L* 90, a catchlight is clipped, "
        "and a threshold low enough to include the sclera makes the whole zone a candidate.",
    )
    sclera_specular_max_ab_distance: float = Field(
        default=8.0,
        gt=0.0,
        description="A catchlight is the light source reflected off a near-white surface, so it "
        "sits close to the sclera's own a*b*. A bright patch further away than this is something "
        "else and must not be removed by the specular rule.",
    )
    sclera_specular_max_area: float = Field(
        default=0.25,
        gt=0.0,
        le=1.0,
        description="A bright near-neutral blob larger than this share of the mask is the sclera "
        "itself, not a glint - so it is kept. Only small connected blobs are removed.",
    )

    # --- is the sclera usable as a white reference? (white_balance.py) ------
    # What matters for white balance is the sclera's *chromaticity*, not its
    # lightness: an underexposed frame has a dark but perfectly usable sclera,
    # and L* tracks exposure rather than the light's colour. So the criteria
    # below are relative - the two eyes against each other, the sclera against
    # the skin in the same frame - and the one absolute L* left is a sanity
    # floor, not a judgement.

    sclera_min_l_floor: float = Field(
        default=20.0,
        description="Sanity floor only. Below this median L* the mask is on something that is "
        "not the white of an eye at any exposure. Deliberately far below a plausible sclera: "
        "this is not the reliability test, it catches a mask that landed on a lash line.",
    )
    sclera_ab_spread_percentile: float = Field(
        default=75.0,
        gt=50.0,
        le=100.0,
        description="Which quantile of the a*b* distance is reported as the sample's spread. The "
        "median is too forgiving: a third of the zone can be eyeliner and leave it at zero.",
    )
    sclera_max_ab_spread: float = Field(
        default=6.0,
        gt=0.0,
        description="Above this a*b* spread the sample is not one surface: it straddles "
        "sclera and something else. A clean sclera stays narrow whatever the light is doing "
        "to it. PRELIMINARY - a real sclera carries visible vessels, and how much spread "
        "that costs is a question for the reference set.",
    )
    sclera_eye_agreement_max_dab: float = Field(
        default=6.0,
        gt=0.0,
        description="Distance in a*b* (lightness excluded - the two eyes are rarely lit equally) "
        "between the left and right sclera. Both eyes see the same light, so a disagreement "
        "above this means at least one mask is not on sclera.",
    )
    # Whether a sclera sample is really the white of the eye.
    #
    # It used to be one test: brighter than the skin by a margin. That was wrong
    # at both ends of the skin-tone axis. On very light or overexposed skin it
    # is simply not satisfiable - the first live frame had skin at L* 86.9 and
    # refused both eyes - while on deep skin it passes anything, because almost
    # everything in the eye socket is brighter than L* 35.
    #
    # The purpose was always to reject lid shadow, and lid shadow is best
    # recognised INSIDE the eye. Three tests now, all of which must hold; the
    # first is skipped when that eye's iris could not be measured.
    #
    # All PRELIMINARY.
    sclera_over_iris_min_l: float = Field(
        default=8.0,
        gt=0.0,
        description="The sclera must be at least this much lighter than the iris of the SAME eye. "
        "The strong test, and the only one that is purely internal to the eye: whatever the skin "
        "tone, whatever the exposure, the white is the lightest thing in there. Small enough that "
        "a pale grey iris still leaves room - that is the case it must not break.",
    )
    sclera_under_skin_max_l: float = Field(
        default=6.0,
        ge=0.0,
        description="The sclera may be DARKER than the skin by at most this. Replaces 'brighter "
        "than the skin by a margin', which fair skin cannot satisfy. Deep lid shadow sits 20-30 "
        "L* under the cheek and is still caught; a sclera a few L* under very light skin is "
        "normal and now passes. The whole of the test when that eye's iris could not be measured, "
        "and the floor under it when it could - see sclera_socket_light_ratio.",
    )
    sclera_socket_light_ratio: float = Field(
        default=0.55,
        gt=0.0,
        le=1.0,
        description="How much of the light on the cheek the eye socket is assumed to get. Used "
        "ONLY when that eye's iris was measured, and only to widen sclera_under_skin_max_l: the "
        "sclera may read down to this FRACTION OF THE SKIN'S LUMINANCE. A fixed drop in L* is not "
        "a fixed amount of shade - L*+16 goes as the cube root of luminance, so 6 L* under skin "
        "at L* 32 is a shadow of 0.67 while the same 6 L* under skin at L* 85 is 0.83, and the "
        "test silently got twice as strict on the fair face it then refused. A ratio is the same "
        "statement about shade at every skin tone; the brow and the lids shade the socket by "
        "roughly the same fraction whoever the face belongs to. 0.55 is shade, not shadow - deep "
        "lid shadow is 0.3 and below, which is what the test is for. It loosens EVERY skin tone, "
        "deep skin included: sclera_under_skin_max_l only binds below about L* 17. What holds "
        "steady on deep skin is the verdicts, because lid shadow there is caught by the iris and "
        "chroma tests. PRELIMINARY.",
    )
    sclera_chroma_ratio: float = Field(
        default=0.6,
        gt=0.0,
        le=1.0,
        description="The sclera's C* must be below this fraction of the skin's. MARKEDLY less "
        "chromatic, not merely 'no more chromatic': a sample that landed on lid skin has the "
        "skin's own chroma, so a tolerance-shaped test passes it - which is precisely the failure "
        "this is here to catch. Relative to the skin in the SAME photograph, so a colour cast "
        "moves both together and cancels.",
    )
    sclera_chroma_floor: float = Field(
        default=6.0,
        gt=0.0,
        description="...but never stricter than this in absolute C*. Without a floor, low-chroma "
        "skin would demand an impossibly neutral sclera - and the whole point of the rewrite is "
        "that very light, low-chroma skin was the case the old test could not satisfy.",
    )
    white_balance_enabled: bool = Field(
        default=False,
        description="Master switch for the whole white-balance step. OFF by default, because "
        "sclera_target_ab has never been measured and an uncalibrated correction biases every "
        "photo cool by the size of the real sclera's warmth - a systematic error on the one axis "
        "the product is sold on. A caller who has not thought about that gets no correction and "
        "a lowered confidence, which is honest, rather than a confident wrong answer. Development "
        "turns it on explicitly with COLOR_ENGINE_WHITE_BALANCE_ENABLED=true; `report` turns it "
        "on for itself, because measuring what the correction would do is the whole point of that "
        "command. See sclera_target_calibrated.",
    )
    sclera_target_calibrated: bool = Field(
        default=False,
        description="Has sclera_target_ab been measured on a real reference set? Defaults to "
        "false and is published in the contract as white_balance.target_calibrated, so a consumer "
        "can see that the correction is pulling the sclera to exact neutral - which a healthy "
        "sclera is not - and that every result carries a systematic cool bias of that size. Set "
        "it to true in the same change that sets a measured sclera_target_ab, never before.",
    )
    sclera_target_ab: tuple[float, float] = Field(
        default=(0.0, 0.0),
        description="The (a*, b*) a healthy sclera is expected to sit at under D65. A real sclera "
        "is not perfectly neutral - it is usually a touch warm - and correcting it to exact white "
        "would push every photo cool by that amount. PRELIMINARY: (0, 0) until the reference set "
        "exists. The stability report prints the median a*b* of the sclera across the daylight "
        "frames, and that measurement is what this value becomes.",
    )

    # --- the scene, as the white-balance fallback (white_balance.py) --------
    # Only the segmenter's background class. Clothes are deliberately out: one
    # saturated dress fills half the frame and drags a grey-world estimate to
    # its complement.

    scene_erode_ratio: float = Field(
        default=0.02,
        ge=0.0,
        description="Erosion of the background class, in face widths. The segmenter is soft on "
        "the person's outline, and the halo it leaves is hair and skin, not scene.",
    )
    scene_min_pixels: int = Field(
        default=2000,
        description="Background pixels that must survive clipping and shadow rejection before "
        "the grey-world estimate means anything. PRELIMINARY.",
    )
    scene_clip_level: int = Field(
        default=250,
        ge=1,
        le=255,
        description="A channel at or above this is at the sensor's ceiling. A blown window is a "
        "picture of the clipping, not of the light, and its colour is a lie.",
    )
    scene_min_l: float = Field(
        default=12.0,
        description="Below this L* a background pixel is a dark corner - a picture of the noise "
        "floor - and carries no illuminant information.",
    )
    scene_minkowski_p: float = Field(
        default=6.0,
        gt=0.0,
        description="The p of the shades-of-grey estimate: p=1 is grey-world, p=inf is white-"
        "patch. 6 is the usual compromise and the value the original paper reports best results "
        "at. PRELIMINARY - worth re-checking on the reference set.",
    )
    scene_spread_percentile: float = Field(
        default=75.0,
        gt=50.0,
        le=100.0,
        description="Which quantile of the a*b* distance describes how varied the background is.",
    )
    scene_uniform_ab_spread: float = Field(
        default=6.0,
        gt=0.0,
        description="Below this a*b* spread the background is one flat surface rather than a "
        "scene. PRELIMINARY.",
    )
    scene_uniform_max_chroma: float = Field(
        default=12.0,
        gt=0.0,
        description="A flat background this chromatic is unusable: from one frame there is no "
        "telling a white wall under an orange lamp from an orange wall under a white one. A flat "
        "*neutral* background is the good case and is kept - it is the combination that is "
        "ambiguous. PRELIMINARY.",
    )

    # --- how far a correction may go (white_balance.py) ---------------------

    max_correction_duv: float = Field(
        default=0.10,
        gt=0.0,
        description="Beyond this distance from D65 in CIE 1976 u'v' the photo is rejected as "
        "color_cast instead of corrected: a sodium lamp or a coloured wash cannot be undone "
        "honestly, because whole regions of the spectrum are simply not in the frame. For scale: "
        "D75 is 0.011 away, D50 0.023, a fluorescent tube ~0.04, a tungsten bulb 0.081 - and "
        "tungsten is a room lamp we do want to correct, which is what sets this number. "
        "PRELIMINARY.",
    )
    cast_consensus_min_chroma: float = Field(
        default=18.0,
        gt=0.0,
        description="With no illuminant estimate at all, a frame is *suspected* of a cast only if "
        "even its least chromatic zone is this saturated. It is a suspicion and never a refusal - "
        "the alternative failure is turning away a warm-toned person in front of a beige wall, "
        "which would fall on warm colour types systematically. PRELIMINARY.",
    )
    cast_consensus_max_hue_spread_deg: float = Field(
        default=45.0,
        gt=0.0,
        description="...and only if every zone points the same way within this arc. One coloured "
        "thing in a frame is a coloured thing; the whole frame agreeing on a direction is a "
        "light. PRELIMINARY.",
    )

    # --- confidence knock-downs (white_balance.py) --------------------------
    # Stage 3 computes the published confidence. These are the multipliers this
    # stage contributes, reported in debug only - white balance has no business
    # publishing a confidence of its own.

    confidence_factor_single_eye: float = Field(
        default=0.9,
        gt=0.0,
        le=1.0,
        description="One sclera instead of two: no cross-check that the mask is on sclera.",
    )
    confidence_factor_scene: float = Field(
        default=0.75,
        gt=0.0,
        le=1.0,
        description="Grey-world over the background rather than a near-neutral reference.",
    )
    confidence_factor_no_estimate: float = Field(
        default=0.6,
        gt=0.0,
        le=1.0,
        description="No estimate at all: the samples are honest but the light is unknown.",
    )
    confidence_factor_cast_suspected: float = Field(
        default=0.5,
        gt=0.0,
        le=1.0,
        description="No estimate, and every zone in the frame is strongly and consistently "
        "coloured. That is a cast or a coincidence, and one frame cannot tell them apart - so the "
        "photo is kept and trusted less rather than refused.",
    )

    # --- low light (quality.py) ---------------------------------------------
    # Every threshold here is a *question*, never an answer. Deep skin under
    # good light measures like light skin in the dark, so a dark reading only
    # opens the question and the sclera and the scene close it. See
    # quality.low_light.

    low_light_skin_l: float = Field(
        default=32.0,
        description="Below this median skin L* the frame *might* be underexposed - or the person "
        "might have deep skin. On its own this decides nothing. PRELIMINARY.",
    )
    low_light_max_shadow_fraction: float = Field(
        default=0.35,
        ge=0.0,
        le=1.0,
        description="Share of the skin mask below low_light_shadow_l that raises the same "
        "question: half a face in the dark is not a photo to measure undertone from. "
        "PRELIMINARY.",
    )
    low_light_max_skin_l_iqr: float = Field(
        default=14.0,
        description="L* IQR inside a cheek above which the sample is noisy rather than uniform. "
        "Cheeks only - the forehead polygon is big enough to straddle a real gradient on a good "
        "photo. PRELIMINARY.",
    )
    low_light_sclera_l_ok: float = Field(
        default=50.0,
        description="A sclera at least this bright settles it: the light was there, and the dark "
        "skin reading is the person, not the exposure. Sclera reflectance barely varies between "
        "people, which is what makes this the better of the two answers. PRELIMINARY.",
    )
    low_light_scene_l_ok: float = Field(
        default=35.0,
        description="Same, from the scene behind the person: a properly exposed background means "
        "the frame is exposed. Weaker than the sclera - a bright wall and a dark room are a "
        "plausible pair - which is why the sclera is consulted first. PRELIMINARY.",
    )
    confidence_factor_exposure_unknown: float = Field(
        default=0.8,
        gt=0.0,
        le=1.0,
        description="The skin reads dark and there is neither a sclera nor a scene to tell a dark "
        "photo from dark skin. The photo is kept - refusing on a signal we cannot interpret is "
        "the one outcome that is certainly wrong - and trusted less.",
    )

    # --- uneven light (quality.py) ------------------------------------------
    # Measured in STOPS - the base-2 log of the ratio of linear luminance - and
    # deliberately not in L*.
    #
    # L* is a compressed function of luminance, so the same lighting ratio
    # produces a different L* gap at different skin tones: a 2x difference in
    # light is about 19 L* on light skin and about 11 on deep skin. A threshold
    # in L* would therefore reject light-skinned people for lighting that deep-
    # skinned people get away with, and miss genuinely side-lit photos of the
    # latter - wrong in both directions, and along the one axis where being
    # wrong is least acceptable. A ratio of luminances is a property of the
    # LIGHT and cancels the reflectance of the skin it fell on.
    #
    # One stop = one doubling. All four are PRELIMINARY.

    uneven_light_warn_stops: float = Field(
        default=0.5,
        gt=0.0,
        description="Difference in light between the two cheeks, in stops, above which the photo "
        "is usable but trusted less. 0.5 is roughly a 1.4x difference - noticeable, and normal "
        "for someone sitting beside a window.",
    )
    uneven_light_reject_stops: float = Field(
        default=1.4,
        gt=0.0,
        description="Above this the photo is refused: one cheek is getting twice the light of the "
        "other, and no white balance fixes a shadow. The live frame that prompted this measured "
        "1.9 stops.",
    )
    uneven_light_forehead_warn_stops: float = Field(
        default=0.8,
        gt=0.0,
        description="Same, for the forehead against the mean of the cheeks. Looser on purpose: "
        "the forehead is the most exposed plane of a face and reads brighter than the cheeks "
        "under perfectly even light.",
    )
    uneven_light_forehead_reject_stops: float = Field(
        default=1.5,
        gt=0.0,
        description="And the refusal threshold for that pair.",
    )
    confidence_factor_uneven_light: float = Field(
        default=0.7,
        gt=0.0,
        le=1.0,
        description="The light is uneven but not hopeless. The undertone is still readable, just "
        "less certainly, because part of what is being measured is a shadow.",
    )

    # --- overexposure (sampling.py, quality.py) ----------------------------
    #
    # A blown highlight is not a bright colour, it is a MISSING colour: once a
    # channel hits the ceiling the sensor has stopped recording, and what comes
    # back is the ceiling rather than the skin. It reads as very light and
    # almost neutral, which is indistinguishable from "cool, light skin" to
    # every measurement downstream - and that is exactly the mistake worth
    # catching, because it moves temperature, depth and contrast at once.
    #
    # ALL PRELIMINARY.

    clipping_channel_level: int = Field(
        default=250,
        ge=1,
        le=255,
        description="A pixel counts as clipped when any 8-bit channel reaches this. Below 255 on "
        "purpose: the frame has been through JPEG and a downscale by the time it is measured, and "
        "both pull a genuinely saturated pixel a few counts under the ceiling. 250 catches those "
        "without claiming an ordinary highlight is blown.",
    )
    zone_clipped_fraction_max: float = Field(
        default=0.15,
        ge=0.0,
        le=1.0,
        description="Share of a zone's pixels that may be clipped before the zone is unusable. Not "
        "zero: a stray specular dot on a cheekbone is normal and the percentile trim already "
        "removes it. Eight per cent is a patch, not a dot.",
    )
    blown_lightness_min: float = Field(
        default=90.0,
        gt=0.0,
        le=100.0,
        description="The second signal, for a zone that survived clipping but is still gone: very "
        "high L* WITH collapsed chroma. Either alone is innocent - fair skin really is light, and "
        "a near-neutral sample can be a grey eye - but together they are what a highlight rolling "
        "off looks like after a resize has averaged the clipped pixels back under the ceiling.",
    )
    blown_chroma_max: float = Field(
        default=4.5,
        gt=0.0,
        description="The chroma half of that pair. Applied only to zones that SHOULD carry colour "
        "- skin, hair, iris. Never to the sclera, which is bright and near-neutral by nature and "
        "would fail this test while being perfectly measured.",
    )
    max_overexposed_skin_zones: int = Field(
        default=2,
        ge=1,
        le=3,
        description="Refuse the photo once this many of the three skin sub-zones are blown. Two, "
        "because one survivor is not a measurement of a face - it is a measurement of one patch, "
        "and the cheeks and the forehead do not agree closely enough for that to stand in.",
    )
    confidence_factor_overexposed: float = Field(
        default=0.6,
        gt=0.0,
        le=1.0,
        description="One skin sub-zone was blown and the other two carried the measurement. "
        "Readable, but a third of the evidence was thrown away and the rest is near the top of "
        "the range where chroma compresses.",
    )

    # --- shadow, for the quality gate (sampling.py, quality.py) -------------

    low_light_shadow_l: float = Field(
        default=18.0,
        description="L* below which a skin pixel counts as being in shadow rather than lit. The "
        "share of the skin mask under it is measured before any correction, because the zone "
        "samplers drop that tail on purpose and the evidence is gone by the time skin is a "
        "LabSample. PRELIMINARY: wants checking against the reference set.",
    )

    # --- garment colour (garment.py) ---------------------------------------
    # PRELIMINARY, like every threshold here: set on the dev catalog (flat shots
    # on white, and shop photos on a model), not on a labelled set.

    garment_min_short_side: int = Field(
        default=120,
        description="Shortest side below which a product photo is rejected as low_resolution. "
        "Far lower than a face's floor: a garment is measured as a whole, not as a 10 px iris, "
        "and shop feeds serve narrow full-length shots (170 x 606 is common).",
    )
    garment_border_fraction: float = Field(
        default=0.03,
        gt=0.0,
        lt=0.25,
        description="Width of the frame border, as a fraction of the short side, that is taken "
        "to be background on a photo without a person.",
    )
    garment_background_delta_e: float = Field(
        default=12.0,
        gt=0.0,
        description="CIE76 distance from the border's median colour beyond which a pixel is "
        "garment rather than backdrop. Below ~8 the shadow under a flat lay counts as garment.",
    )
    garment_person_fraction: float = Field(
        default=0.01,
        ge=0.0,
        lt=1.0,
        description="Share of the frame the segmenter must call hair or skin before the photo "
        "is treated as worn, and the segmenter's clothes class is trusted over the backdrop.",
    )
    garment_min_coverage: float = Field(
        default=0.03,
        gt=0.0,
        lt=1.0,
        description="Share of the frame the garment mask must cover; below it the photo is "
        "rejected as no_garment instead of measured on a few stray pixels.",
    )
    garment_l_percentile_low: float = Field(
        default=3.0,
        ge=0.0,
        lt=50.0,
        description="Drop garment pixels below this L* percentile - the deepest folds.",
    )
    garment_l_percentile_high: float = Field(
        default=97.0,
        gt=50.0,
        le=100.0,
        description="Drop garment pixels above this L* percentile - sheen and studio highlights. "
        "Wider than skin's: fabric shading is the garment's colour, a highlight on a nose is not.",
    )
    garment_clusters: int = Field(
        default=4,
        ge=1,
        le=8,
        description="How many colours k-means looks for before near-identical ones are merged.",
    )
    garment_merge_delta_e: float = Field(
        default=10.0,
        ge=0.0,
        description="Distance (CIE76, lightness at half weight) under which two near-neutral "
        "clusters are one colour in light and shade.",
    )
    garment_chromatic_c: float = Field(
        default=12.0,
        ge=0.0,
        description="Chroma above which a cluster has a hue worth comparing. Below it, black, "
        "white, grey and the palest beiges are merged on distance instead.",
    )
    garment_merge_hue_degrees: float = Field(
        default=12.0,
        ge=0.0,
        le=180.0,
        description="Two chromatic clusters within this many degrees of hue are one dye in light "
        "and in shade, however far apart their lightness.",
    )
    garment_min_share: float = Field(
        default=0.05,
        ge=0.0,
        lt=1.0,
        description="A colour covering less of the garment than this is a trim, a button or a "
        "print dot's edge, and is not reported.",
    )

    max_pixels_per_zone: int = Field(
        default=20000,
        gt=0,
        description="Cap on pixels measured per zone. Above it we take a deterministic random "
        "subsample: the median is already stable and this bounds the cost.",
    )
    sampling_seed: int = Field(
        default=17, description="Seed for that subsample, so results are reproducible."
    )

    def face_landmarker_path(self) -> Path:
        return self.models_dir / FACE_LANDMARKER_FILENAME

    def segmenter_path(self) -> Path:
        return self.models_dir / SELFIE_SEGMENTER_FILENAME
