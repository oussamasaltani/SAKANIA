import api from './api';

export const getProperties = (params) => api.get('/properties', { params });

export const getProperty = (id) => api.get(`/properties/${id}`);

export const searchProperties = (params) => api.get('/properties/search', { params });

/** Params the public landing page renders. Kept here so the warm-up call and the
 *  page cannot drift apart. */
export const FEATURED_PROPERTIES_PARAMS = { per_page: 6 };

/**
 * How long a resolved featured-properties response may be reused, in ms.
 *
 * Short by design: this only needs to cover the window between the warm-up call
 * at bootstrap and the Home page asking for the same thing as it mounts. A
 * visitor who leaves the landing page and comes back later gets a fresh request,
 * exactly as before.
 */
const FEATURED_TTL_MS = 5000;

let featured = { promise: null, value: undefined, readyAt: 0 };

const sameParams = (params) => {
  if (!params) return FEATURED_PROPERTIES_PARAMS.per_page === 6;
  const keys = Object.keys(params);
  return (
    keys.length === 1 &&
    keys[0] === 'per_page' &&
    params.per_page === FEATURED_PROPERTIES_PARAMS.per_page
  );
};

const reusable = () => featured.promise && Date.now() - featured.readyAt < FEATURED_TTL_MS;

/**
 * The landing page's featured properties.
 *
 * This request used to be issued from the Home page's mount effect, which is the
 * very last thing on the critical path: the entry bundle, React, the router and
 * the animation library must all download and execute before Home mounts at all,
 * and the effect's data could not paint until it resolved. On a throttled
 * connection the main thread was busy for seconds around the commit, so the
 * request left late and the featured-property cards — the page's Largest
 * Contentful Paint — waited behind all of it.
 *
 * `prefetchFeaturedProperties` starts the same request during module evaluation,
 * so it runs while the bundle is still downloading. The response is then held
 * briefly and handed to the page two ways:
 *
 *   - `peekFeaturedProperties()` returns it synchronously, so an already-warm
 *     response is in state on Home's very first render instead of one commit
 *     later;
 *   - `getFeaturedProperties()` returns the same promise, so a page that mounts
 *     before the response lands joins the in-flight request rather than opening
 *     a second, duplicate one.
 *
 * Either way exactly one request is made and the page renders the same data.
 */
export const getFeaturedProperties = (params = FEATURED_PROPERTIES_PARAMS) => {
  if (sameParams(params) && reusable()) {
    return featured.promise;
  }

  const promise = api.get('/properties', { params });
  featured = { promise, value: undefined, readyAt: Number.POSITIVE_INFINITY };

  const settle = (value) => {
    if (featured.promise === promise) {
      featured = { promise, value, readyAt: Date.now() };
    }
  };
  promise.then((response) => settle(response), () => settle(undefined));

  return promise;
};

/**
 * The already-resolved featured-properties response, or undefined if there is
 * none to reuse. Used to seed component state without an extra round trip.
 */
export const peekFeaturedProperties = () =>
  featured.value !== undefined && Date.now() - featured.readyAt < FEATURED_TTL_MS
    ? featured.value
    : undefined;

/**
 * Starts the landing page's featured-properties request immediately, during
 * module evaluation, rather than waiting for React to mount the Home page.
 *
 * Failures are swallowed: the page makes the same call during mount and handles
 * the outcome there.
 */
export const prefetchFeaturedProperties = () => {
  getFeaturedProperties().catch(() => {});
};
