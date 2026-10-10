import { useState, useEffect } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import DashboardLayout from '../../components/layouts/DashboardLayout';
import { getProperty, approveProperty, rejectProperty, deleteProperty } from '../../services/adminService';
import { getImageUrl } from '../../utils/getImageUrl';
import toast from 'react-hot-toast';
import { motion } from 'framer-motion';
import {
  HiBanknotes,
  HiCheck,
  HiChevronLeft,
  HiChevronRight,
  HiHome,
  HiIdentification,
  HiMapPin,
  HiRectangleStack,
  HiSparkles,
  HiTrash,
  HiUser,
  HiXMark,
} from 'react-icons/hi2';
import { Button } from '../../components/ui/button';
import UserAvatar from '../../components/common/UserAvatar';
import {
  AdminPage,
  ConfirmModal,
  DetailHeader,
  DetailSkeleton,
  InfoRow,
  SectionCard,
  Spinner,
  StatusBadge,
} from '../../components/admin/AdminUI';

const PROPERTY_STATUS_MAP = { approved: 'emerald', pending: 'amber', rejected: 'rose' };

const PropertyDetail = () => {
  const { id } = useParams();
  const navigate = useNavigate();
  const [property, setProperty] = useState(null);
  const [loading, setLoading] = useState(true);
  const [processing, setProcessing] = useState(false);
  const [modal, setModal] = useState({ open: false, title: '', message: '', action: null });
  const [currentImage, setCurrentImage] = useState(0);

  useEffect(() => {
    getProperty(id)
      .then((res) => setProperty(res.data?.data || res.data))
      .catch(() => {
        toast.error('Failed to load property');
        navigate('/admin/properties');
      })
      .finally(() => setLoading(false));
  }, [id, navigate]);

  const handleAction = async () => {
    if (!modal.action) return;
    setProcessing(true);
    try {
      if (modal.action === 'approve') {
        await approveProperty(id);
        toast.success('Property approved');
        setProperty((prev) => ({ ...prev, status: 'approved' }));
      } else if (modal.action === 'reject') {
        await rejectProperty(id);
        toast.success('Property rejected');
        setProperty((prev) => ({ ...prev, status: 'rejected' }));
      } else if (modal.action === 'delete') {
        await deleteProperty(id);
        toast.success('Property deleted');
        navigate('/admin/properties');
        return;
      }
      setModal({ open: false, title: '', message: '', action: null });
    } catch (err) {
      toast.error(err.response?.data?.message || 'Action failed');
    } finally {
      setProcessing(false);
    }
  };

  if (loading) {
    return (
      <DashboardLayout>
        <div className="p-6 lg:p-8 max-w-6xl mx-auto">
          <DetailSkeleton media />
        </div>
      </DashboardLayout>
    );
  }

  if (!property) return null;

  const images = property.images || [];
  const monthlyRent = property.price_per_month || property.price || null;

  const details = [
    { label: 'Type', value: property.type || property.property_type },
    {
      label: 'Price',
      value: monthlyRent ? `${Number(monthlyRent).toLocaleString()} MAD/mo` : null,
    },
    { label: 'Area', value: property.area ? `${property.area} m²` : null },
    { label: 'Rooms', value: property.rooms },
    { label: 'Bathrooms', value: property.bathrooms },
    { label: 'Floor', value: property.floor },
    { label: 'Capacity', value: property.capacity ? `${property.capacity} persons` : null },
  ].filter((d) => d.value);

  return (
    <DashboardLayout>
      <AdminPage className="max-w-6xl">
        <DetailHeader
          title={property.title}
          subtitle={`Property #${property.id}`}
          onBack={() => navigate('/admin/properties')}
        >
          <StatusBadge status={property.status} map={PROPERTY_STATUS_MAP} />
        </DetailHeader>

        <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
          <div className="lg:col-span-2 space-y-6">
            <div className="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
              {images.length > 0 ? (
                <div className="relative">
                  <motion.div
                    key={currentImage}
                    initial={{ opacity: 0.4 }}
                    animate={{ opacity: 1 }}
                    transition={{ duration: 0.25 }}
                    className="h-72 sm:h-96 bg-slate-100 flex items-center justify-center overflow-hidden"
                  >
                    <img
                      src={getImageUrl(images[currentImage])}
                      alt=""
                      className="w-full h-full object-cover"
                    />
                  </motion.div>

                  <div className="absolute top-3 left-3 flex items-center gap-1.5">
                    <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-white/85 backdrop-blur-sm border border-slate-200/80 text-slate-700 shadow-2xs">
                      <HiRectangleStack className="w-3.5 h-3.5 text-slate-400" />
                      {currentImage + 1} / {images.length}
                    </span>
                  </div>

                  {images.length > 1 && (
                    <>
                      <motion.button
                        type="button"
                        whileTap={{ scale: 0.9 }}
                        onClick={() =>
                          setCurrentImage((prev) => (prev > 0 ? prev - 1 : images.length - 1))
                        }
                        aria-label="Previous image"
                        className="absolute left-3 top-1/2 -translate-y-1/2 w-9 h-9 bg-white/85 backdrop-blur-sm rounded-xl border border-slate-200/80 flex items-center justify-center shadow-md hover:bg-white transition-colors"
                      >
                        <HiChevronLeft className="w-5 h-5 text-slate-700" />
                      </motion.button>
                      <motion.button
                        type="button"
                        whileTap={{ scale: 0.9 }}
                        onClick={() =>
                          setCurrentImage((prev) => (prev < images.length - 1 ? prev + 1 : 0))
                        }
                        aria-label="Next image"
                        className="absolute right-3 top-1/2 -translate-y-1/2 w-9 h-9 bg-white/85 backdrop-blur-sm rounded-xl border border-slate-200/80 flex items-center justify-center shadow-md hover:bg-white transition-colors"
                      >
                        <HiChevronRight className="w-5 h-5 text-slate-700" />
                      </motion.button>
                      <div className="absolute bottom-3 left-1/2 -translate-x-1/2 flex gap-1.5 px-2 py-1.5 rounded-full bg-white/70 backdrop-blur-sm">
                        {images.map((_, i) => (
                          <button
                            key={i}
                            type="button"
                            onClick={() => setCurrentImage(i)}
                            aria-label={`View image ${i + 1}`}
                            className={`w-2 h-2 rounded-full transition-all duration-200 ${
                              i === currentImage
                                ? 'bg-gradient-to-b from-orange-500 to-amber-500 w-5'
                                : 'bg-slate-300 hover:bg-slate-400'
                            }`}
                          />
                        ))}
                      </div>
                    </>
                  )}
                </div>
              ) : (
                <div className="h-72 bg-slate-50 border-b border-slate-100 flex flex-col items-center justify-center gap-3 text-slate-400">
                  <HiHome className="w-10 h-10" />
                  <p className="text-sm font-medium">No photos uploaded for this property</p>
                </div>
              )}
            </div>

            <SectionCard title="Location" icon={HiMapPin}>
              <p className="text-sm font-bold text-slate-900">
                {property.city || property.location || 'Location not provided'}
              </p>
              {property.address && (
                <p className="text-sm text-slate-500 mt-1 leading-relaxed">{property.address}</p>
              )}
            </SectionCard>

            {property.description && (
              <SectionCard title="Description" icon={HiRectangleStack}>
                <p className="text-sm text-slate-600 leading-relaxed whitespace-pre-wrap">
                  {property.description}
                </p>
              </SectionCard>
            )}

            {details.length > 0 && (
              <SectionCard title="Property Details" icon={HiHome}>
                <div className="grid grid-cols-2 sm:grid-cols-3 gap-4">
                  {details.map((d) => (
                    <InfoRow key={d.label} label={d.label} value={d.value} />
                  ))}
                </div>
              </SectionCard>
            )}

            {property.amenities && property.amenities.length > 0 && (
              <SectionCard title="Amenities" icon={HiSparkles}>
                <div className="flex flex-wrap gap-2">
                  {property.amenities.map((a, i) => (
                    <span
                      key={i}
                      className="px-3 py-1.5 rounded-full text-xs font-semibold bg-orange-50 border border-orange-200/80 text-orange-700 shadow-2xs"
                    >
                      {a}
                    </span>
                  ))}
                </div>
              </SectionCard>
            )}
          </div>

          <div className="space-y-6">
            {property.owner && (
              <SectionCard title="Owner" icon={HiUser}>
                <div className="flex items-center gap-3">
                  <div className="w-11 h-11 rounded-full bg-slate-100 border border-slate-200/60 flex items-center justify-center overflow-hidden shrink-0">
                    <UserAvatar user={property.owner} className="w-full h-full text-slate-500 text-sm font-bold" />
                  </div>
                  <div className="min-w-0">
                    <p className="text-sm font-bold text-slate-900 truncate">
                      {property.owner.full_name || property.owner.name}
                    </p>
                    <p className="text-xs text-slate-500 truncate">{property.owner.email}</p>
                  </div>
                </div>
              </SectionCard>
            )}

            {monthlyRent ? (
              <SectionCard title="Monthly Rent" icon={HiBanknotes}>
                <p className="text-2xl font-extrabold tracking-tight text-slate-900">
                  {Number(monthlyRent).toLocaleString()}
                  <span className="text-sm font-bold text-slate-500 ml-1">MAD/mo</span>
                </p>
              </SectionCard>
            ) : null}

            <SectionCard title="Moderation" icon={HiIdentification}>
              <p className="text-sm text-slate-500 leading-relaxed">
                {property.status === 'pending'
                  ? 'This listing is awaiting review before it goes live.'
                  : property.status === 'approved'
                    ? 'This listing is live and visible to tenants.'
                    : 'This listing was rejected and is hidden from tenants.'}
              </p>

              {property.status === 'pending' && (
                <div className="grid grid-cols-2 gap-2 mt-5">
                  <Button
                    onClick={() =>
                      setModal({
                        open: true,
                        title: 'Approve Property',
                        message: 'Approve this property listing for public display?',
                        action: 'approve',
                      })
                    }
                    disabled={processing}
                    className="rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white"
                  >
                    {processing ? <Spinner /> : <HiCheck className="w-4 h-4" />}
                    Approve
                  </Button>
                  <Button
                    onClick={() =>
                      setModal({
                        open: true,
                        title: 'Reject Property',
                        message: 'Reject this property submission? The owner will be asked to revise it.',
                        action: 'reject',
                      })
                    }
                    disabled={processing}
                    variant="outline"
                    className="rounded-xl border-amber-200 text-amber-700 hover:bg-amber-50 hover:border-amber-300"
                  >
                    <HiXMark className="w-4 h-4" /> Reject
                  </Button>
                </div>
              )}

              <Button
                onClick={() =>
                  setModal({
                    open: true,
                    title: 'Delete Property',
                    message: 'Are you sure you want to delete this property? This cannot be undone.',
                    action: 'delete',
                  })
                }
                variant="destructive"
                className={`w-full rounded-xl ${property.status === 'pending' ? 'mt-2' : 'mt-5'}`}
              >
                <HiTrash className="w-4 h-4" /> Delete Property
              </Button>
            </SectionCard>

            <Button
              onClick={() => navigate('/admin/properties')}
              variant="outline"
              className="w-full rounded-xl border-slate-200 text-slate-700 hover:bg-slate-50"
            >
              Back to Properties
            </Button>
          </div>
        </div>

        <ConfirmModal
          isOpen={modal.open}
          title={modal.title}
          message={modal.message}
          confirmLabel={modal.action === 'delete' ? 'Delete' : 'Confirm'}
          variant={modal.action === 'delete' ? 'danger' : modal.action === 'approve' ? 'success' : 'default'}
          onConfirm={handleAction}
          onCancel={() => setModal({ open: false, title: '', message: '', action: null })}
          loading={processing}
        />
      </AdminPage>
    </DashboardLayout>
  );
};

export default PropertyDetail;