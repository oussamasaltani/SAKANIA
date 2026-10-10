/**
 * The property form rules, mirroring what the API validates on
 * POST /owner/properties and PUT /owner/properties/:id.
 *
 * The form hands every value over as a string, exactly as the browser
 * produces it, so the checks below are the same ones Laravel applies: a blank
 * string fails "required", a decimal string passes "numeric", and capacity
 * starts at 1. Running this before the request lets an incomplete form be
 * reported by name instead of coming back as a 422 the owner cannot act on.
 *
 * Returns the message to show, or null when the payload is acceptable.
 */
export const PROPERTY_TYPES = ['apartment', 'house', 'studio', 'villa', 'office'];

const isNumber = (value) =>
  value !== '' && value !== null && value !== undefined && Number.isFinite(Number(value));

const isWholeNumber = (value) => isNumber(value) && Number.isInteger(Number(value));

export const validatePropertyForm = (form) => {
  if (!form.title?.trim()) return 'Please enter a property title.';
  if (!form.description?.trim()) return 'Please enter a description.';
  if (!PROPERTY_TYPES.includes(form.type)) return 'Please select a property type.';
  if (!form.location?.trim()) return 'Please enter a location.';
  if (!isNumber(form.area) || Number(form.area) < 0) return 'Area must be 0 or more.';
  if (!isNumber(form.price_per_month) || Number(form.price_per_month) < 0) {
    return 'Price per month must be 0 or more.';
  }
  if (!isWholeNumber(form.rooms) || Number(form.rooms) < 0) {
    return 'Rooms must be a whole number of 0 or more.';
  }
  if (!isWholeNumber(form.bathrooms) || Number(form.bathrooms) < 0) {
    return 'Bathrooms must be a whole number of 0 or more.';
  }
  if (!isWholeNumber(form.floor) || Number(form.floor) < 0) {
    return 'Floor must be a whole number of 0 or more.';
  }
  if (!isWholeNumber(form.capacity) || Number(form.capacity) < 1) {
    return 'Capacity must be a whole number of at least 1.';
  }
  return null;
};
