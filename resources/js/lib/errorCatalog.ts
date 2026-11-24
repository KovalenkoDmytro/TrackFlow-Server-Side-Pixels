/**
 * Plain-language guidance for delivery error codes, grouped on the platform and home pages.
 * Codes come from the stored response body (Google Ads errorCode values, Meta markers);
 * anything unknown falls back to a generic guide that still shows the raw message.
 */
/**
 * Kept free of runtime imports so the pure helpers can run under `node --test` (tests/js).
 */
export interface ErrorGuide {
  title: string;
  explanation: string;
  steps: string[];
  docsUrl?: string;
  /** Link text; defaults to "<platform> documentation". */
  docsLabel?: string;
  /** Platform this guide is written for. A guide is never shown on another platform's card. */
  platform?: string;
  /** Main action: 'settings' sends the merchant to the platform's TrackFlow settings (reconnect, new token). */
  cta?: 'settings';
  ctaLabel?: string;
}

const GOOGLE_UPLOAD_DOCS = 'https://developers.google.com/google-ads/api/reference/rpc/latest/ConversionUploadErrorEnum.ConversionUploadError';
const GOOGLE_AUTH_DOCS = 'https://developers.google.com/google-ads/api/docs/oauth/overview';
const META_CAPI_DOCS = 'https://developers.facebook.com/docs/marketing-api/conversions-api/';
const META_CAPI_TOKEN_DOCS = 'https://developers.facebook.com/docs/marketing-api/conversions-api/get-started#access-token';
const GA4_MP_DOCS = 'https://developers.google.com/analytics/devguides/collection/protocol/ga4';

/** Default documentation per platform, used by the generic guide. */
export const PLATFORM_DOCS: Record<string, { url: string; label: string }> = {
  google_ads: { url: GOOGLE_UPLOAD_DOCS, label: 'Google Ads documentation' },
  meta: { url: META_CAPI_DOCS, label: 'Meta Conversions API documentation' },
  ga4: { url: GA4_MP_DOCS, label: 'GA4 Measurement Protocol documentation' },
};

const GOOGLE_GUIDES: Record<string, ErrorGuide> = {
  CONVERSION_PRECEDES_EVENT: {
    title: 'Event time is earlier than the ad click',
    explanation: 'Google Ads only accepts a conversion that happened after its click. The event time sent was earlier than the click time Google recorded. This happens when a page event fires within seconds of the click, or when the store and the Google Ads account use different time zones.',
    steps: [
      'In Google Ads, open Admin → Account settings and compare the account time zone with your Shopify store time zone (Settings → General).',
      'If only browse events such as View Item are affected, no action is needed: purchases and bidding are not affected unless you optimise for that event.',
      'If purchases or checkout steps are affected, contact TrackFlow support with the error code below.',
    ],
    docsUrl: GOOGLE_UPLOAD_DOCS,
  },
  TOO_RECENT_EVENT: {
    title: 'The ad click is too recent',
    explanation: 'Google needs a few hours after a click before it accepts a conversion for it.',
    steps: ['No action is usually needed. If this keeps happening for most events, contact TrackFlow support.'],
    docsUrl: GOOGLE_UPLOAD_DOCS,
  },
  EVENT_NOT_FOUND: {
    title: 'Google could not find the ad click',
    explanation: 'The click ID on the event does not exist in the connected Google Ads account. The click may belong to another account or the link was copied.',
    steps: ['Check that the connected customer ID is the account that runs your ads.', 'If you run ads from several accounts, connect the account that owns these clicks.'],
    docsUrl: GOOGLE_UPLOAD_DOCS,
  },
  UNPARSEABLE_GCLID: {
    title: 'The click ID could not be read',
    explanation: 'The gclid captured on the storefront is malformed, often because a link was edited or shortened.',
    steps: ['Make sure ad links keep the gclid parameter unchanged (no URL shorteners that strip parameters).'],
    docsUrl: GOOGLE_UPLOAD_DOCS,
  },
  INVALID_CONVERSION_ACTION: {
    title: 'The conversion action is missing or disabled',
    explanation: 'Google rejected the upload because the mapped conversion action does not exist or is not an upload-type action.',
    steps: ['Open TrackFlow Google Ads settings and reconnect, so TrackFlow recreates the conversion actions.', 'In Google Ads, check that the TrackFlow conversion actions are enabled.'],
    docsUrl: GOOGLE_UPLOAD_DOCS,
    cta: 'settings',
    ctaLabel: 'Open Google Ads settings',
  },
  TOO_RECENT_CONVERSION_ACTION: {
    title: 'The conversion action was created very recently',
    explanation: 'Google does not accept conversions for an action in the first hours after it is created.',
    steps: ['Wait a few hours. Uploads start being accepted automatically.'],
    docsUrl: GOOGLE_UPLOAD_DOCS,
  },
  CONVERSION_TRACKING_NOT_ENABLED_AT_IMPRESSION_TIME: {
    title: 'Conversion tracking was off when the ad was shown',
    explanation: 'Google only accepts conversions for clicks that happened while conversion tracking was enabled on the account.',
    steps: ['No action needed for these events. New clicks will be accepted.'],
    docsUrl: GOOGLE_UPLOAD_DOCS,
  },
  CLICK_CONVERSION_ALREADY_EXISTS: {
    title: 'Google already has this conversion',
    explanation: 'The same conversion was uploaded before, so Google ignored the duplicate. Your totals are not double counted.',
    steps: ['No action needed.'],
    docsUrl: GOOGLE_UPLOAD_DOCS,
  },
  ORDER_ID_ALREADY_IN_USE: {
    title: 'Order ID already used',
    explanation: 'A conversion with the same order ID already exists in Google Ads, so this one was ignored as a duplicate.',
    steps: ['No action needed unless the count looks wrong. Then contact TrackFlow support.'],
    docsUrl: GOOGLE_UPLOAD_DOCS,
  },
  CUSTOMER_NOT_ACCEPTED_CUSTOMER_DATA_TERMS: {
    title: 'Customer data terms not accepted',
    explanation: 'Enhanced conversions need the Google Ads customer data terms to be accepted on the account.',
    steps: ['In Google Ads, open Goals → Settings → Enhanced conversions and accept the customer data terms.'],
    docsUrl: GOOGLE_UPLOAD_DOCS,
  },
  UNAUTHORIZED_CUSTOMER: {
    title: 'TrackFlow cannot upload to this account',
    explanation: 'The connected Google user no longer has access to the customer ID, or the manager link was removed.',
    steps: ['Reconnect Google Ads in TrackFlow settings with a user who has Standard or Admin access to the account.'],
    cta: 'settings',
    ctaLabel: 'Open Google Ads settings',
  },
  USER_PERMISSION_DENIED: {
    title: 'Google Ads permission denied',
    explanation: 'The connected Google user does not have permission for this account (or the manager account ID is missing).',
    steps: ['Open Google Ads settings in TrackFlow and check the customer ID and manager (MCC) ID.', 'Reconnect with a user that has access to the account.'],
    cta: 'settings',
    ctaLabel: 'Open Google Ads settings',
  },
  GOOGLE_ADS_AUTH: {
    title: 'Google Ads sign-in could not be refreshed',
    explanation: 'TrackFlow could not get a fresh Google sign-in token for your Google Ads account, so these conversions were not uploaded.',
    steps: [
      'If this happened only for a short time, no action is needed: new events upload once Google answers again.',
      'If it keeps happening, open TrackFlow Google Ads settings and reconnect Google Ads with a user who has access to the account.',
    ],
    docsUrl: GOOGLE_AUTH_DOCS,
    docsLabel: 'Google Ads sign-in documentation',
    cta: 'settings',
    ctaLabel: 'Open Google Ads settings',
  },
};

const META_GUIDES: Record<string, ErrorGuide> = {
  META_ACCESS_TOKEN: {
    title: 'Meta access token is invalid or expired',
    explanation: 'Meta rejected the events because the Conversions API access token no longer works.',
    steps: ['In Meta Events Manager, generate a new Conversions API access token for your pixel.', 'Paste it in TrackFlow Meta settings and save.'],
    docsUrl: META_CAPI_TOKEN_DOCS,
    docsLabel: 'Meta Conversions API access token guide',
    cta: 'settings',
    ctaLabel: 'Update Meta access token',
  },
  meta_no_events_received: {
    title: 'Meta did not confirm the events',
    explanation: 'Meta answered without confirming receipt, so these events may not appear in Events Manager.',
    steps: ['Check in Meta Events Manager that the pixel ID in TrackFlow settings is correct.', 'Make sure the Conversions API token belongs to the same pixel.'],
    docsUrl: META_CAPI_DOCS,
    docsLabel: 'Meta Conversions API documentation',
    cta: 'settings',
    ctaLabel: 'Open Meta settings',
  },
};

const GUIDES: Record<string, ErrorGuide> = {
  ...tag(GOOGLE_GUIDES, 'google_ads'),
  ...tag(META_GUIDES, 'meta'),
};

function tag(guides: Record<string, ErrorGuide>, platform: string): Record<string, ErrorGuide> {
  return Object.fromEntries(Object.entries(guides).map(([code, guide]) => [code, { ...guide, platform }]));
}

const ALIASES: Record<string, string> = {
  DUPLICATE_CLICK_CONVERSION_IN_REQUEST: 'CLICK_CONVERSION_ALREADY_EXISTS',
  DUPLICATE_ORDER_ID: 'ORDER_ID_ALREADY_IN_USE',
  CONVERSION_PRECEDES_CLICK: 'CONVERSION_PRECEDES_EVENT',
};

/**
 * Guide for an issue on `platform` (google_ads, meta, ga4, ...). A platform-specific guide is used
 * only on its own platform; anything else gets a generic guide that names the issue's platform.
 */
export function guideFor(code: string, platform: string, platformLabel: string): ErrorGuide {
  const key = ALIASES[code] ?? code;
  const guide = Object.prototype.hasOwnProperty.call(GUIDES, key) ? GUIDES[key] : undefined;
  if (guide && (guide.platform === undefined || guide.platform === platform)) return guide;

  const docs = PLATFORM_DOCS[platform];
  const token = code === 'ACCESS_TOKEN_INVALID' || code === 'META_ACCESS_TOKEN' || code === 'GOOGLE_ADS_AUTH';
  if (token) {
    return {
      title: `${platformLabel} sign-in is invalid or expired`,
      explanation: `${platformLabel} rejected these events because TrackFlow's access to your ${platformLabel} account no longer works.`,
      steps: [`Open TrackFlow ${platformLabel} settings and reconnect, or enter a new access token.`],
      docsUrl: docs?.url,
      docsLabel: docs?.label,
      platform,
      cta: 'settings',
      ctaLabel: `Open ${platformLabel} settings`,
    };
  }

  return {
    title: code === 'UNKNOWN' ? `${platformLabel} rejected some events` : `${platformLabel} rejected some events (${code})`,
    explanation: `${platformLabel} did not accept these events. The latest message from ${platformLabel} is shown below.`,
    steps: [
      `Check that the ${platformLabel} connection in TrackFlow settings is still valid.`,
      'If the problem continues, contact TrackFlow support and include the error code and message below.',
    ],
    docsUrl: docs?.url,
    docsLabel: docs?.label,
    platform,
  };
}
