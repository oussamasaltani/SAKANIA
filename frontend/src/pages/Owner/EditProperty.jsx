import { useState, useEffect } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { motion, AnimatePresence } from 'framer-motion';
import { getProperty, updateProperty } from '../../services/ownerService';
import toast from 'react-hot-toast';
import {
  HiArrowLeft,
  HiArrowUpTray,
  HiXMark,
  HiCheckCircle,
  HiIdentification,
  HiBuildingOffice2,
  HiMapPin,
  HiCurrencyDollar,
  HiArrowsPointingOut,
  HiSquares2X2,
  HiHomeModern,
  HiSparkles,
  HiBuildingOffice,
  HiUsers,
  HiDocumentText,
  HiChevronDown,
  HiPhoto,
} from 'react-icons/hi2';
import { Button } from '../../components/ui/button';
import { Input, Textarea } from '../../components/ui/input';
import { Skeleton } from '../../components/ui/skeleton';
import { getImageUrl } from '../../utils/getImageUrl';
import BrandLoader from '../../components/common/BrandLoader';
import { PROPERTY_TYPES, validatePropertyForm } from '../../utils/validatePropertyForm';

const amenityList = ['wifi', 'parking', 'air_conditioning', 'balcony', 'garden', 'pool', 'security', 'elevator', 'furnished', 'laundry'];

const containerVariants = {
  hidden: { opacity: 0 },
  visible: {
    opacity: 1,
    transition: {
      staggerChildren: 0.06,
      delayChildren: 0.05,
    },
  },
};

const sectionVariants = {
  hidden: { opacity: 0, y: 18, scale: 0.99 },
  visible: {
    opacity: 1,
    y: 0,
    scale: 1,
    transition: { type: 'spring', stiffness: 260, damping: 22 },
  },
};

const EditPropertySkeleton = () => (
  <div className="space-y-6">
    <div className="flex items-center gap-3 pb-2 border-b border-slate-200/60">
      <div className="h-7 w-1.5 rounded-full bg-slate-200" />
      <Skeleton className="h-8 w-56 rounded-lg" />
    </div>

    <div className="rounded-2xl border border-slate-200/80 bg-white p-6 space-y-5">
      <div className="flex items-center gap-3 pb-1 border-b border-slate-100">
        <Skeleton className="h-10 w-10 rounded-xl" />
        <div className="space-y-2">
          <Skeleton className="h-4 w-40 rounded-md" />
          <Skeleton className="h-3 w-56 rounded-md" />
        </div>
      </div>
      <div className="space-y-4">
        <div>
          <Skeleton className="h-3 w-24 rounded-md mb-2" />
          <Skeleton className="h-11 w-full rounded-xl" />
        </div>
        <div>
          <Skeleton className="h-3 w-28 rounded-md mb-2" />
          <Skeleton className="h-28 w-full rounded-xl" />
        </div>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <Skeleton className="h-11 w-full rounded-xl" />
          <Skeleton className="h-11 w-full rounded-xl" />
        </div>
      </div>
    </div>

    <div className="rounded-2xl border border-slate-200/80 bg-white p-6 space-y-5">
      <div className="flex items-center gap-3 pb-1 border-b border-slate-100">
        <Skeleton className="h-10 w-10 rounded-xl" />
        <div className="space-y-2">
          <Skeleton className="h-4 w-28 rounded-md" />
          <Skeleton className="h-3 w-64 rounded-md" />
        </div>
      </div>
      <div className="grid grid-cols-2 sm:grid-cols-3 gap-4">
        {[...Array(6)].map((_, i) => (
          <Skeleton key={i} className="h-11 w-full rounded-xl" />
        ))}
      </div>
    </div>

    <div className="rounded-2xl border border-slate-200/80 bg-white p-6 space-y-5">
      <div className="flex items-center gap-3 pb-1 border-b border-slate-100">
        <Skeleton className="h-10 w-10 rounded-xl" />
        <div className="space-y-2">
          <Skeleton className="h-4 w-24 rounded-md" />
          <Skeleton className="h-3 w-72 rounded-md" />
        </div>
      </div>
      <div className="grid grid-cols-2 sm:grid-cols-3 gap-3">
        {[...Array(3)].map((_, i) => (
          <Skeleton key={i} className="h-28 w-full rounded-xl" />
        ))}
      </div>
    </div>
  </div>
);

const EditProperty = () => {
  const { id } = useParams();
  const navigate = useNavigate();
  const [form, setForm] = useState({
    title: '',
    description: '',
    type: '',
    location: '',
    area: '',
    price_per_month: '',
    rooms: '',
    bathrooms: '',
    floor: '',
    capacity: '',
    amenities: [],
  });
  const [existingImages, setExistingImages] = useState([]);
  const [removedImages, setRemovedImages] = useState([]);
  const [newImages, setNewImages] = useState([]);
  const [newImagePreviews, setNewImagePreviews] = useState([]);
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    getProperty(id)
      .then((res) => {
        const p = res.data?.data || res.data;
        setForm({
          title: p?.title || '',
          description: p?.description || '',
          type: p?.type || '',
          location: p?.location || '',
          area: p?.area || '',
          price_per_month: p?.price_per_month || '',
          rooms: p?.rooms || '',
          bathrooms: p?.bathrooms || '',
          floor: p?.floor || '',
          capacity: p?.capacity || '',
          amenities: p?.amenities || [],
        });
        setExistingImages(p?.images || []);
      })
      .catch(() => {
        toast.error('Failed to load property');
        navigate('/owner/properties');
      })
      .finally(() => setLoading(false));
  }, [id, navigate]);

  const handleChange = (e) => {
    setForm({ ...form, [e.target.name]: e.target.value });
  };

  const handleAmenityToggle = (amenity) => {
    setForm((prev) => ({
      ...prev,
      amenities: prev.amenities.includes(amenity)
        ? prev.amenities.filter((a) => a !== amenity)
        : [...prev.amenities, amenity],
    }));
  };

  const handleRemoveExistingImage = (index) => {
    const img = existingImages[index];
    setRemovedImages((prev) => [...prev, img.id]);
    setExistingImages((prev) => prev.filter((_, i) => i !== index));
  };

  const handleNewImageChange = (e) => {
    const files = Array.from(e.target.files || []);
    const totalImages = existingImages.length + newImages.length + files.length;
    if (totalImages > 5) {
      toast.error('Maximum 5 images allowed');
      return;
    }
    const updated = [...newImages, ...files].slice(0, 5 - existingImages.length);
    setNewImages(updated);

    const previews = [];
    updated.forEach((file) => {
      const reader = new FileReader();
      reader.onloadend = () => {
        previews.push(reader.result);
        if (previews.length === updated.length) {
          setNewImagePreviews([...previews]);
        }
      };
      reader.readAsDataURL(file);
    });
  };

  const removeNewImage = (index) => {
    setNewImages((prev) => prev.filter((_, i) => i !== index));
    setNewImagePreviews((prev) => prev.filter((_, i) => i !== index));
  };

  const toBase64 = (file) =>
    new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.readAsDataURL(file);
      reader.onload = () => resolve(reader.result);
      reader.onerror = reject;
    });

  const handleSubmit = async (e) => {
    e.preventDefault();
    // The update endpoint validates the same ten fields the create form does,
    // so the whole rule set is checked before the request goes out.
    const problem = validatePropertyForm(form);
    if (problem) {
      toast.error(problem);
      return;
    }
    setSubmitting(true);
    try {
      const imagesBase64 = await Promise.all(newImages.map((img) => toBase64(img)));
      await updateProperty(id, {
        ...form,
        images: imagesBase64,
        removed_images: removedImages.length > 0 ? removedImages : undefined,
      });
      toast.success('Property updated successfully');
      navigate('/owner/properties');
    } catch (err) {
      toast.error(err.response?.data?.message || 'Failed to update property');
    } finally {
      setSubmitting(false);
    }
  };

  if (loading) {
    return (
      <div className="p-4 sm:p-6 lg:p-8 max-w-7xl mx-auto space-y-6 antialiased">
        <EditPropertySkeleton />
      </div>
    );
  }

  return (
    <motion.div
      initial={{ opacity: 0, y: 12 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.35, ease: 'easeOut' }}
      className="p-4 sm:p-6 lg:p-8 max-w-7xl mx-auto space-y-6 antialiased selection:bg-orange-500 selection:text-white"
    >
      {/* Header Section */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-2 border-b border-slate-200/60">
        <div className="relative">
          <div className="flex items-center gap-3">
            <div className="h-7 w-1.5 rounded-full bg-gradient-to-b from-orange-500 to-amber-500 shadow-sm" />
            <div className="flex items-center gap-3 flex-wrap">
              <h1 className="text-2xl sm:text-3xl font-extrabold tracking-tight text-slate-900 font-display">
                Edit Property
              </h1>
            </div>
          </div>
          <p className="text-sm text-slate-500 mt-1.5 ml-4.5 font-normal leading-relaxed">
            Update your property details
          </p>
        </div>

        <Button
          variant="ghost"
          onClick={() => navigate('/owner/properties')}
          className="self-start -ml-2 sm:self-auto h-9 px-3 rounded-xl text-slate-500 hover:text-slate-900 hover:bg-slate-100 transition-colors active:scale-[0.98] duration-200 cursor-pointer gap-1.5"
        >
          <HiArrowLeft className="w-4 h-4" />
          <span className="text-sm font-medium">Back to Properties</span>
        </Button>
      </div>

      <motion.form
        onSubmit={handleSubmit}
        variants={containerVariants}
        initial="hidden"
        animate="visible"
        className="space-y-6"
      >
        {/* Basic Information */}
        <motion.section
          variants={sectionVariants}
          className="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-5 sm:p-6 space-y-5"
        >
          <div className="flex items-center gap-3 pb-1 border-b border-slate-100">
            <div className="w-10 h-10 rounded-xl bg-orange-50 text-orange-600 border border-orange-100 flex items-center justify-center shadow-xs shrink-0">
              <HiDocumentText className="w-5 h-5" />
            </div>
            <div>
              <h2 className="text-base font-bold text-slate-900 tracking-tight">Basic Information</h2>
              <p className="text-xs text-slate-500 mt-0.5">Essential details to identify your property.</p>
            </div>
          </div>

          <div>
            <label className="block text-xs font-semibold uppercase tracking-wider text-slate-700 mb-1.5">
              Title *
            </label>
            <div className="relative group">
              <HiIdentification className="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 group-focus-within:text-orange-500 transition-colors pointer-events-none" />
              <Input
                type="text"
                name="title"
                value={form.title}
                onChange={handleChange}
                required
                placeholder="Property title"
                className="pl-10 h-11 text-sm bg-white border-slate-200/90 rounded-xl focus:border-orange-500 focus:ring-2 focus:ring-orange-500/15 transition-all placeholder:text-slate-400 shadow-xs"
              />
            </div>
          </div>

          <div>
            <label className="block text-xs font-semibold uppercase tracking-wider text-slate-700 mb-1.5">
              Description *
            </label>
            <Textarea
              name="description"
              value={form.description}
              onChange={handleChange}
              rows={4}
              required
              placeholder="Describe your property..."
              className="min-h-[120px] w-full rounded-xl border border-slate-200/90 bg-white px-4 py-3 text-sm text-slate-900 shadow-xs transition-all placeholder:text-slate-400 focus:border-orange-500 focus:ring-2 focus:ring-orange-500/15 focus:outline-none resize-none"
            />
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label className="block text-xs font-semibold uppercase tracking-wider text-slate-700 mb-1.5">
                Type *
              </label>
              <div className="relative group">
                <HiBuildingOffice2 className="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 group-focus-within:text-orange-500 transition-colors pointer-events-none" />
                <select
                  name="type"
                  value={form.type}
                  onChange={handleChange}
                  required
                  className="w-full appearance-none pl-10 pr-10 h-11 rounded-xl border border-slate-200/90 bg-white text-sm font-medium text-slate-700 shadow-xs hover:border-slate-300 focus:border-orange-500 focus:ring-2 focus:ring-orange-500/15 focus:outline-none transition-all cursor-pointer"
                >
                  <option value="">Select type</option>
                  {PROPERTY_TYPES.map((t) => (
                    <option key={t} value={t}>{t}</option>
                  ))}
                </select>
                <HiChevronDown className="absolute right-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 pointer-events-none" />
              </div>
            </div>

            <div>
              <label className="block text-xs font-semibold uppercase tracking-wider text-slate-700 mb-1.5">
                Location *
              </label>
              <div className="relative group">
                <HiMapPin className="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 group-focus-within:text-orange-500 transition-colors pointer-events-none" />
                <Input
                  type="text"
                  name="location"
                  value={form.location}
                  onChange={handleChange}
                  required
                  placeholder="e.g. Maarif, Casablanca"
                  className="pl-10 h-11 text-sm bg-white border-slate-200/90 rounded-xl focus:border-orange-500 focus:ring-2 focus:ring-orange-500/15 transition-all placeholder:text-slate-400 shadow-xs"
                />
              </div>
            </div>
          </div>
        </motion.section>

        {/* Details */}
        <motion.section
          variants={sectionVariants}
          className="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-5 sm:p-6 space-y-5"
        >
          <div className="flex items-center gap-3 pb-1 border-b border-slate-100">
            <div className="w-10 h-10 rounded-xl bg-orange-50 text-orange-600 border border-orange-100 flex items-center justify-center shadow-xs shrink-0">
              <HiSquares2X2 className="w-5 h-5" />
            </div>
            <div>
              <h2 className="text-base font-bold text-slate-900 tracking-tight">Details</h2>
              <p className="text-xs text-slate-500 mt-0.5">Specifications such as size, rooms and capacity.</p>
            </div>
          </div>

          <div className="grid grid-cols-2 sm:grid-cols-3 gap-4">
            <div>
              <label className="block text-xs font-semibold uppercase tracking-wider text-slate-700 mb-1.5">
                Price (MAD/mo) *
              </label>
              <div className="relative group">
                <HiCurrencyDollar className="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 group-focus-within:text-orange-500 transition-colors pointer-events-none" />
                <Input
                  type="number"
                  name="price_per_month"
                  value={form.price_per_month}
                  onChange={handleChange}
                  required
                  min="0"
                  placeholder="0"
                  className="pl-10 h-11 text-sm bg-white border-slate-200/90 rounded-xl focus:border-orange-500 focus:ring-2 focus:ring-orange-500/15 transition-all placeholder:text-slate-400 shadow-xs"
                />
              </div>
            </div>

            <div>
              <label className="block text-xs font-semibold uppercase tracking-wider text-slate-700 mb-1.5">
                Area (m²) *
              </label>
              <div className="relative group">
                <HiArrowsPointingOut className="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 group-focus-within:text-orange-500 transition-colors pointer-events-none" />
                <Input
                  type="number"
                  name="area"
                  value={form.area}
                  onChange={handleChange}
                  required
                  min="0"
                  placeholder="0"
                  className="pl-10 h-11 text-sm bg-white border-slate-200/90 rounded-xl focus:border-orange-500 focus:ring-2 focus:ring-orange-500/15 transition-all placeholder:text-slate-400 shadow-xs"
                />
              </div>
            </div>

            <div>
              <label className="block text-xs font-semibold uppercase tracking-wider text-slate-700 mb-1.5">
                Rooms *
              </label>
              <div className="relative group">
                <HiHomeModern className="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 group-focus-within:text-orange-500 transition-colors pointer-events-none" />
                <Input
                  type="number"
                  name="rooms"
                  value={form.rooms}
                  onChange={handleChange}
                  required
                  min="0"
                  placeholder="0"
                  className="pl-10 h-11 text-sm bg-white border-slate-200/90 rounded-xl focus:border-orange-500 focus:ring-2 focus:ring-orange-500/15 transition-all placeholder:text-slate-400 shadow-xs"
                />
              </div>
            </div>

            <div>
              <label className="block text-xs font-semibold uppercase tracking-wider text-slate-700 mb-1.5">
                Bathrooms *
              </label>
              <div className="relative group">
                <HiSparkles className="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 group-focus-within:text-orange-500 transition-colors pointer-events-none" />
                <Input
                  type="number"
                  name="bathrooms"
                  value={form.bathrooms}
                  onChange={handleChange}
                  required
                  min="0"
                  placeholder="0"
                  className="pl-10 h-11 text-sm bg-white border-slate-200/90 rounded-xl focus:border-orange-500 focus:ring-2 focus:ring-orange-500/15 transition-all placeholder:text-slate-400 shadow-xs"
                />
              </div>
            </div>

            <div>
              <label className="block text-xs font-semibold uppercase tracking-wider text-slate-700 mb-1.5">
                Floor *
              </label>
              <div className="relative group">
                <HiBuildingOffice className="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 group-focus-within:text-orange-500 transition-colors pointer-events-none" />
                <Input
                  type="number"
                  name="floor"
                  value={form.floor}
                  onChange={handleChange}
                  required
                  min="0"
                  placeholder="0"
                  className="pl-10 h-11 text-sm bg-white border-slate-200/90 rounded-xl focus:border-orange-500 focus:ring-2 focus:ring-orange-500/15 transition-all placeholder:text-slate-400 shadow-xs"
                />
              </div>
            </div>

            <div>
              <label className="block text-xs font-semibold uppercase tracking-wider text-slate-700 mb-1.5">
                Capacity *
              </label>
              <div className="relative group">
                <HiUsers className="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 group-focus-within:text-orange-500 transition-colors pointer-events-none" />
                <Input
                  type="number"
                  name="capacity"
                  value={form.capacity}
                  onChange={handleChange}
                  required
                  min="1"
                  placeholder="1"
                  className="pl-10 h-11 text-sm bg-white border-slate-200/90 rounded-xl focus:border-orange-500 focus:ring-2 focus:ring-orange-500/15 transition-all placeholder:text-slate-400 shadow-xs"
                />
              </div>
            </div>
          </div>
        </motion.section>

        {/* Amenities */}
        <motion.section
          variants={sectionVariants}
          className="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-5 sm:p-6 space-y-5"
        >
          <div className="flex items-center gap-3 pb-1 border-b border-slate-100">
            <div className="w-10 h-10 rounded-xl bg-orange-50 text-orange-600 border border-orange-100 flex items-center justify-center shadow-xs shrink-0">
              <HiSparkles className="w-5 h-5" />
            </div>
            <div>
              <h2 className="text-base font-bold text-slate-900 tracking-tight">Amenities</h2>
              <p className="text-xs text-slate-500 mt-0.5">Select the amenities included with this property.</p>
            </div>
          </div>

          <div className="flex flex-wrap gap-2">
            {amenityList.map((amenity) => (
              <button
                key={amenity}
                type="button"
                aria-pressed={form.amenities.includes(amenity)}
                onClick={() => handleAmenityToggle(amenity)}
                className={`px-4 py-2 rounded-xl text-sm font-semibold focus-visible:ring-2 focus-visible:ring-orange-500/30 transition-all duration-200 active:scale-[0.98] cursor-pointer capitalize ${
                  form.amenities.includes(amenity)
                    ? 'border-orange-500 bg-orange-50/60 shadow-md shadow-orange-500/10 text-orange-600'
                    : 'border border-slate-200 bg-white text-slate-900 hover:border-slate-300 hover:bg-slate-50'
                }`}
              >
                {amenity}
              </button>
            ))}
          </div>
        </motion.section>

        {/* Photos */}
        <motion.section
          variants={sectionVariants}
          className="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-5 sm:p-6 space-y-5"
        >
          <div className="flex items-center gap-3 pb-1 border-b border-slate-100">
            <div className="w-10 h-10 rounded-xl bg-orange-50 text-orange-600 border border-orange-100 flex items-center justify-center shadow-xs shrink-0">
              <HiPhoto className="w-5 h-5" />
            </div>
            <div>
              <h2 className="text-base font-bold text-slate-900 tracking-tight">Photos</h2>
              <p className="text-xs text-slate-500 mt-0.5">
                Upload up to 5 images total. The first image will be the main photo.
              </p>
            </div>
          </div>

          <AnimatePresence initial={false}>
            {(existingImages.length > 0 || newImagePreviews.length > 0) && (
              <motion.div
                key="edit-previews"
                initial={{ opacity: 0, height: 0 }}
                animate={{ opacity: 1, height: 'auto' }}
                exit={{ opacity: 0, height: 0 }}
                transition={{ duration: 0.2 }}
                className="overflow-hidden"
              >
                <div className="grid grid-cols-2 sm:grid-cols-3 gap-3">
                  {existingImages.map((img, i) => (
                    <div
                      key={`existing-${i}`}
                      className="relative group rounded-xl overflow-hidden border border-slate-200/80 bg-slate-50 shadow-xs"
                    >
                      <img src={getImageUrl(img)} alt="" className="w-full h-28 object-cover" />
                      <Button
                        type="button"
                        variant="destructive"
                        size="icon-sm"
                        onClick={() => handleRemoveExistingImage(i)}
                        className="absolute top-2 right-2 opacity-0 group-hover:opacity-100 shadow-md active:scale-[0.98] transition-all duration-200 cursor-pointer"
                      >
                        <HiXMark className="w-4 h-4" />
                      </Button>
                      {i === 0 && (
                        <span className="absolute bottom-2 left-2 px-2 py-0.5 bg-orange-500 text-white text-xs rounded-md font-medium">
                          Main
                        </span>
                      )}
                    </div>
                  ))}
                  {newImagePreviews.map((preview, i) => (
                    <div
                      key={`new-${i}`}
                      className="relative group rounded-xl overflow-hidden border border-slate-200/80 bg-slate-50 shadow-xs"
                    >
                      <img src={preview} alt="" className="w-full h-28 object-cover" />
                      <Button
                        type="button"
                        variant="destructive"
                        size="icon-sm"
                        onClick={() => removeNewImage(i)}
                        className="absolute top-2 right-2 opacity-0 group-hover:opacity-100 shadow-md active:scale-[0.98] transition-all duration-200 cursor-pointer"
                      >
                        <HiXMark className="w-4 h-4" />
                      </Button>
                    </div>
                  ))}
                </div>
              </motion.div>
            )}
          </AnimatePresence>

          {existingImages.length + newImages.length < 5 && (
            <label
              className={`flex flex-col items-center justify-center w-full h-40 rounded-2xl border-2 border-dashed border-slate-200 bg-slate-50/60 cursor-pointer hover:border-orange-500/40 hover:bg-orange-50/30 transition-all duration-200 p-6 text-center group ${existingImages.length + newImagePreviews.length > 0 ? 'mt-4' : ''}`}
            >
              <HiArrowUpTray className="w-10 h-10 text-slate-300 group-hover:text-orange-500 transition-colors duration-200 mx-auto mb-2" />
              <span className="text-sm font-medium text-slate-500 group-hover:text-slate-700 transition-colors duration-200">
                Click to upload ({existingImages.length + newImages.length}/5)
              </span>
              <input
                type="file"
                accept="image/*"
                multiple
                onChange={handleNewImageChange}
                className="hidden"
              />
            </label>
          )}
        </motion.section>

        {/* Actions */}
        <div className="flex flex-col-reverse sm:flex-row items-stretch sm:items-center justify-end gap-3 pt-2">
          <Button
            type="button"
            variant="outline"
            onClick={() => navigate('/owner/properties')}
            className="h-11 px-5 rounded-xl border-slate-200 bg-white text-slate-700 hover:bg-slate-50 hover:border-slate-300 font-medium text-sm transition-all duration-200 active:scale-[0.98] cursor-pointer"
          >
            Cancel
          </Button>
          <Button
            type="submit"
            disabled={submitting}
            className="w-full sm:w-auto h-11 px-6 bg-gradient-to-r from-orange-500 to-amber-600 hover:from-orange-600 hover:to-amber-700 text-white font-semibold text-sm rounded-xl shadow-md hover:shadow-lg hover:shadow-orange-500/25 active:scale-[0.98] transition-all duration-200 cursor-pointer"
          >
            {submitting ? (
              <div className="flex items-center justify-center gap-2">
                <BrandLoader size="small" showText={false} />
                <span>Updating...</span>
              </div>
            ) : (
              <div className="flex items-center justify-center gap-2">
                <HiCheckCircle className="w-4 h-4" />
                <span>Update Property</span>
              </div>
            )}
          </Button>
        </div>
      </motion.form>
    </motion.div>
  );
};

export default EditProperty;