/**
 * Segmented control geometry, shared by every toggle (period, mode, chart/table).
 * The selected pill sits inside the grey track, so its radius is concentric with it:
 * inner radius = track radius − track padding.
 */
export const SEGMENTED_TRACK_RADIUS = 8;
export const SEGMENTED_TRACK_PADDING = 2;
export const SEGMENTED_PILL_RADIUS = SEGMENTED_TRACK_RADIUS - SEGMENTED_TRACK_PADDING;
