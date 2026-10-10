import { useEffect } from 'react';
import { Link } from 'react-router-dom';
import { AnimatePresence, motion } from 'framer-motion';
import {
  HiArrowLeft,
  HiArrowPath,
  HiCheck,
  HiChevronUpDown,
  HiFunnel,
  HiMagnifyingGlass,
  HiShieldCheck,
  HiTrash,
  HiXMark,
} from 'react-icons/hi2';
import { Button } from '../ui/button';
import { Input } from '../ui/input';
import { cn } from '../../lib/utils';

export const EASE = [0.16, 1, 0.3, 1];

export const formatDate = (value, fallback = '—') => {
  if (!value) return fallback;
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? fallback : date.toLocaleDateString();
};

export const formatDateTime = (value, fallback = '') => {
  if (!value) return fallback;
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? fallback : date.toLocaleString();
};

export const nestedProperty = (record) =>
  record?.property || record?.reservation?.property || null;

export const Money = ({ value, suffix = 'MAD', className = '' }) => (
  <span className={`font-extrabold text-slate-900 ${className}`}>
    {Number(value || 0).toLocaleString()}
    <span className="text-xs font-bold text-slate-500 ml-1">{suffix}</span>
  </span>
);

const TONES = {
  brand: { chip: 'bg-orange-50 text-orange-700 ring-orange-600/20', dot: 'bg-orange-500' },
  emerald: { chip: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20', dot: 'bg-emerald-500' },
  amber: { chip: 'bg-amber-50 text-amber-700 ring-amber-600/20', dot: 'bg-amber-500' },
  rose: { chip: 'bg-rose-50 text-rose-700 ring-rose-600/20', dot: 'bg-rose-500' },
  slate: { chip: 'bg-slate-100 text-slate-600 ring-slate-200', dot: 'bg-slate-400' },
  sky: { chip: 'bg-sky-50 text-sky-700 ring-sky-600/20', dot: 'bg-sky-500' },
  violet: { chip: 'bg-violet-50 text-violet-700 ring-violet-600/20', dot: 'bg-violet-500' },
};

const STATUS_TONE_MAP = {
  approved: 'emerald',
  active: 'emerald',
  accepted: 'emerald',
  paid: 'emerald',
  completed: 'emerald',
  pending: 'amber',
  rejected: 'rose',
  failed: 'rose',
  cancelled: 'rose',
  refunded: 'rose',
  ended: 'rose',
  inactive: 'rose',
  draft: 'slate',
};

const ROLE_TONE_MAP = {
  admin: 'violet',
  owner: 'sky',
  tenant: 'emerald',
};

export const toneFor = (status, map = STATUS_TONE_MAP) => TONES[map?.[status]] || TONES.slate;

const chipBase =
  'inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold ring-1 ring-inset capitalize shadow-2xs';

export const ToneBadge = ({ tone = 'slate', dot = true, icon: Icon, children, className = '' }) => {
  const resolved = TONES[tone] || TONES.slate;
  return (
    <span className={cn(chipBase, resolved.chip, className)}>
      {dot && <span className={`w-1.5 h-1.5 rounded-full shrink-0 ${resolved.dot}`} />}
      {Icon && <Icon className="w-3.5 h-3.5 shrink-0" />}
      {children}
    </span>
  );
};

export const StatusBadge = ({ status, map = STATUS_TONE_MAP, label, className = '' }) => (
  <ToneBadge tone={toneFor(status, map)} className={className}>
    {label || status || 'unknown'}
  </ToneBadge>
);

export const RoleBadge = ({ role, icon: Icon, className = '' }) => (
  <ToneBadge tone={toneFor(role, ROLE_TONE_MAP)} icon={Icon} className={className}>
    {role || 'unknown'}
  </ToneBadge>
);

export const StatusSelect = ({
  value,
  onChange,
  options = [],
  disabled = false,
  loading = false,
  map = STATUS_TONE_MAP,
  ariaLabel = 'Change status',
}) => {
  const resolved = toneFor(value, map);
  return (
    <span className="relative inline-flex items-center">
      <select
        aria-label={ariaLabel}
        value={value || ''}
        onChange={(e) => onChange(e.target.value)}
        disabled={disabled || loading}
        className={cn(
          'appearance-none rounded-full py-1 pl-3 pr-7 text-xs font-semibold capitalize ring-1 ring-inset shadow-2xs cursor-pointer transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-slate-400/30 disabled:opacity-60 disabled:cursor-not-allowed',
          resolved.chip
        )}
      >
        {options.map((option) => {
          const optionValue = typeof option === 'string' ? option : option.value;
          const optionLabel = typeof option === 'string' ? option : option.label;
          return (
            <option key={optionValue} value={optionValue} className="bg-white text-slate-900">
              {optionLabel}
            </option>
          );
        })}
      </select>
      {loading ? (
        <HiArrowPath className="pointer-events-none absolute right-2 w-3 h-3 animate-spin text-current opacity-70" />
      ) : (
        <HiChevronUpDown className="pointer-events-none absolute right-2 w-3 h-3 text-current opacity-60" />
      )}
    </span>
  );
};

import BrandLoader from '../common/BrandLoader';

export const Spinner = ({ className = '', size = 'small', showText = false, text = '' }) => (
  <BrandLoader 
    size={size === 'xs' || size === 'sm' ? 'small' : size === 'lg' ? 'large' : 'small'} 
    showText={showText} 
    text={text}
    className={className} 
  />
);

export const AdminPage = ({ children, className = '' }) => (
  <motion.div
    initial={{ opacity: 0, y: 10 }}
    animate={{ opacity: 1, y: 0 }}
    transition={{ duration: 0.3, ease: 'easeOut' }}
    className={cn('p-6 lg:p-8 max-w-7xl mx-auto space-y-6', className)}
  >
    {children}
  </motion.div>
);

const AccentBar = () => (
  <div className="h-7 w-1.5 rounded-full bg-gradient-to-b from-orange-500 to-amber-500 shadow-sm shrink-0" />
);

export const LiveCountBadge = ({ countText, tone = 'orange' }) => (
  <span
    className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold shadow-2xs ${
      tone === 'slate'
        ? 'bg-slate-50 border border-slate-200/80 text-slate-600'
        : 'bg-orange-50 border border-orange-200/80 text-orange-700'
    }`}
  >
    <span className="relative flex h-2 w-2">
      <span
        className={`animate-ping absolute inline-flex h-full w-full rounded-full opacity-75 ${
          tone === 'slate' ? 'bg-slate-400' : 'bg-orange-400'
        }`}
      />
      <span
        className={`relative inline-flex rounded-full h-2 w-2 ${
          tone === 'slate' ? 'bg-slate-500' : 'bg-orange-500'
        }`}
      />
    </span>
    {countText}
  </span>
);

export const PageHeader = ({ title, subtitle, countText, countTone = 'orange', children }) => (
  <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-2 border-b border-slate-200/60">
    <div className="relative min-w-0">
      <div className="flex items-center gap-3">
        <AccentBar />
        <div className="flex items-center gap-3 flex-wrap">
          <h1 className="text-2xl sm:text-3xl font-extrabold tracking-tight text-slate-900 font-display">
            {title}
          </h1>
          {countText && <LiveCountBadge countText={countText} tone={countTone} />}
        </div>
      </div>
      {subtitle && (
        <p className="text-sm text-slate-500 mt-1.5 ml-4.5 font-normal leading-relaxed">{subtitle}</p>
      )}
    </div>
    {children}
  </div>
);

const backButtonClass =
  'w-10 h-10 flex items-center justify-center rounded-xl border border-slate-200/80 bg-white text-slate-600 shadow-2xs hover:bg-slate-50 hover:text-slate-900 hover:border-slate-300 transition-all shrink-0';

export const BackButton = ({ onClick, title = 'Go back' }) => (
  <motion.button
    type="button"
    whileTap={{ scale: 0.92 }}
    onClick={onClick}
    title={title}
    aria-label={title}
    className={backButtonClass}
  >
    <HiArrowLeft className="w-5 h-5" />
  </motion.button>
);

export const DetailHeader = ({ title, subtitle, onBack, children }) => (
  <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-2 border-b border-slate-200/60">
    <div className="flex items-center gap-4 min-w-0">
      <BackButton onClick={onBack} />
      <div className="flex items-center gap-3 min-w-0">
        <AccentBar />
        <div className="min-w-0">
          <h1 className="text-xl sm:text-2xl font-extrabold tracking-tight text-slate-900 font-display truncate">
            {title}
          </h1>
          {subtitle && <p className="text-sm text-slate-500 mt-0.5 truncate">{subtitle}</p>}
        </div>
      </div>
    </div>
    {children}
  </div>
);

export const StatCard = ({
  label,
  value,
  valueClassName = '',
  icon: Icon,
  iconStyle,
  to,
  onClick,
  title,
  index = 0,
}) => {
  const body = (
    <>
      <div className="flex items-center justify-between gap-2">
        <span className="text-xs font-semibold text-slate-500 uppercase tracking-wider truncate">
          {label}
        </span>
        <div
          className={`w-9 h-9 rounded-xl border flex items-center justify-center shrink-0 ${iconStyle}`}
        >
          <Icon className="w-5 h-5" />
        </div>
      </div>
      <p className={cn('text-2xl font-extrabold tracking-tight text-slate-900 mt-3 truncate', valueClassName)}>
        {value}
      </p>
    </>
  );

  const className =
    'block w-full text-left bg-white rounded-2xl border border-slate-200/80 p-5 shadow-xs transition-all duration-200';

  let inner;
  if (to) {
    inner = (
      <Link to={to} title={title} className={`${className} hover:border-slate-300 hover:shadow-md`}>
        {body}
      </Link>
    );
  } else if (onClick) {
    inner = (
      <motion.button
        type="button"
        onClick={onClick}
        title={title}
        whileTap={{ scale: 0.98 }}
        className={`${className} cursor-pointer hover:border-slate-300 hover:shadow-md`}
      >
        {body}
      </motion.button>
    );
  } else {
    inner = <div className={`${className} hover:border-slate-300 hover:shadow-md`}>{body}</div>;
  }

  return (
    <motion.div
      initial={{ opacity: 0, y: 12 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ delay: index * 0.05, duration: 0.25 }}
      className="min-w-0"
    >
      {inner}
    </motion.div>
  );
};

export const StatGrid = ({ items, columns = 'grid-cols-2 lg:grid-cols-4' }) => (
  <div className={`grid ${columns} gap-4`}>
    {items.map((item, index) => (
      <StatCard key={item.label} {...item} index={index} />
    ))}
  </div>
);

export const Toolbar = ({ children, className = '' }) => (
  <div className={`flex flex-col sm:flex-row gap-3 ${className}`}>{children}</div>
);

export const SearchInput = ({ value, onChange, placeholder = 'Search...' }) => (
  <div className="relative flex-1">
    <HiMagnifyingGlass className="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" />
    <Input
      type="text"
      placeholder={placeholder}
      value={value}
      onChange={(e) => onChange(e.target.value)}
      className="pl-10 h-11 bg-white border-slate-200/80 focus:border-slate-400 focus:ring-slate-400/20 rounded-xl text-sm"
    />
  </div>
);

export const FilterSelect = ({
  value,
  onChange,
  options,
  minWidth = 'min-w-[160px]',
  ariaLabel = 'Filter',
  fullWidth = false,
}) => (
  <div className={fullWidth ? 'relative w-full' : 'relative shrink-0'}>
    <HiFunnel className="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 pointer-events-none" />
    <select
      aria-label={ariaLabel}
      value={value}
      onChange={(e) => onChange(e.target.value)}
      className={cn(
        'appearance-none pl-10 pr-9 py-2.5 h-11 rounded-xl border border-slate-200/80 bg-white text-sm text-slate-700 shadow-2xs focus:border-slate-400 focus:ring-2 focus:ring-slate-400/20 focus:outline-none transition-all font-medium cursor-pointer',
        fullWidth ? 'w-full' : minWidth
      )}
    >
      {options.map((option) => {
        const optionValue = typeof option === 'string' ? option : option.value;
        const optionLabel = typeof option === 'string' ? option : option.label;
        return (
          <option key={optionValue} value={optionValue}>
            {optionLabel}
          </option>
        );
      })}
    </select>
    <HiChevronUpDown className="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 pointer-events-none" />
  </div>
);

export const TableCard = ({ children }) => (
  <div className="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
    <div className="overflow-x-auto">{children}</div>
  </div>
);

export const DataTable = ({ children, className = '' }) => (
  <table className={cn('w-full text-left border-collapse', className)}>{children}</table>
);

export const ThRow = ({ children }) => (
  <tr className="bg-slate-50/70 border-b border-slate-100">{children}</tr>
);

export const Th = ({ children, align = 'left', className = '' }) => (
  <th
    className={cn(
      'text-[11px] font-bold uppercase tracking-wider text-slate-500 px-6 py-4',
      align === 'right' ? 'text-right' : align === 'center' ? 'text-center' : '',
      className
    )}
  >
    {children}
  </th>
);

export const Tr = ({ children, className = '' }) => (
  <tr className={cn('hover:bg-slate-50/60 transition-colors duration-150', className)}>{children}</tr>
);

export const Td = ({ children, align = 'left', className = '' }) => (
  <td
    className={cn(
      'px-6 py-4',
      align === 'right' ? 'text-right' : align === 'center' ? 'text-center' : '',
      className
    )}
  >
    {children}
  </td>
);

export const InlineText = ({ icon: Icon, children, className = '' }) => (
  <div className={cn('flex items-center gap-1.5 text-xs font-medium text-slate-600', className)}>
    {Icon && <Icon className="w-3.5 h-3.5 text-slate-400 shrink-0" />}
    <span className="truncate">{children}</span>
  </div>
);

export const ImageTile = ({ src, icon: Icon, alt = '', size = 'w-11 h-11 rounded-xl' }) => (
  <div
    className={cn(
      size,
      'bg-slate-100 border border-slate-200/60 flex items-center justify-center overflow-hidden shrink-0 shadow-2xs'
    )}
  >
    {src ? (
      <img src={src} alt={alt} className="w-full h-full object-cover" />
    ) : (
      Icon && <Icon className="w-5 h-5 text-slate-400" />
    )}
  </div>
);

export const EmptyState = ({ icon: Icon, title, message, action }) => (
  <div className="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-12 text-center">
    <div className="w-14 h-14 bg-slate-50 border border-slate-100 rounded-2xl flex items-center justify-center mx-auto mb-4 text-slate-400">
      {Icon && <Icon className="w-7 h-7" />}
    </div>
    <h3 className="text-base font-bold text-slate-900 tracking-tight">{title}</h3>
    {message && <p className="text-slate-500 text-sm mt-1 max-w-sm mx-auto leading-relaxed">{message}</p>}
    {action && <div className="mt-5 flex justify-center">{action}</div>}
  </div>
);

export const SectionCard = ({ title, action, icon: Icon, children, body = true, className = '' }) => (
  <div className={cn('bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden', className)}>
    {(title || action) && (
      <div className="flex items-center justify-between gap-3 px-6 py-4 border-b border-slate-100">
        <h2 className="flex items-center gap-2 text-base font-bold text-slate-900 tracking-tight">
          {Icon && <Icon className="w-4 h-4 text-slate-400" />}
          {title}
        </h2>
        {action}
      </div>
    )}
    {body ? <div className="p-6">{children}</div> : children}
  </div>
);

export const ViewAllLink = ({ to, children = 'View all' }) => (
  <Link
    to={to}
    className="inline-flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-orange-600 hover:text-orange-700 transition-colors"
  >
    {children}
  </Link>
);

export const InfoRow = ({ icon: Icon, label, value, valueClassName = '' }) => (
  <div className="flex items-center gap-3 p-4 rounded-xl bg-slate-50/60 border border-slate-100">
    {Icon ? (
      <div className="w-10 h-10 rounded-xl bg-white border border-slate-200/80 flex items-center justify-center shrink-0 text-slate-500">
        <Icon className="w-5 h-5" />
      </div>
    ) : null}
    <div className="min-w-0">
      <p className="text-[11px] font-bold uppercase tracking-wider text-slate-500">{label}</p>
      <p className={cn('text-sm font-bold text-slate-900 truncate mt-0.5', valueClassName)}>
        {value || '—'}
      </p>
    </div>
  </div>
);

const actionTones = {
  neutral: 'text-slate-400 hover:text-slate-700 hover:bg-slate-100',
  brand: 'text-slate-400 hover:text-orange-600 hover:bg-orange-50',
  success: 'text-emerald-600 hover:text-emerald-700 hover:bg-emerald-50',
  warning: 'text-amber-600 hover:text-amber-700 hover:bg-amber-50',
  danger: 'text-slate-400 hover:text-rose-600 hover:bg-rose-50',
};

export const ActionButton = ({ tone = 'neutral', title, children, className = '', ...props }) => (
  <button
    type="button"
    title={title}
    aria-label={title}
    className={cn(
      'p-2 rounded-lg transition-colors duration-150 disabled:opacity-50 disabled:pointer-events-none',
      actionTones[tone],
      className
    )}
    {...props}
  >
    {children}
  </button>
);

export const ActionLink = ({ to, tone = 'neutral', title, children, className = '' }) => (
  <Link
    to={to}
    title={title}
    aria-label={title}
    className={cn('p-2 rounded-lg transition-colors duration-150', actionTones[tone], className)}
  >
    {children}
  </Link>
);

const useEscapeToClose = (open, onClose) => {
  useEffect(() => {
    if (!open) return undefined;
    const onKeyDown = (event) => {
      if (event.key === 'Escape') onClose?.();
    };
    document.addEventListener('keydown', onKeyDown);
    return () => document.removeEventListener('keydown', onKeyDown);
  }, [open, onClose]);
};

const confirmStyles = {
  danger: {
    icon: 'bg-rose-50 border-rose-100 text-rose-600',
    button: 'bg-rose-600 hover:bg-rose-700 text-white',
    fallback: HiTrash,
  },
  success: {
    icon: 'bg-emerald-50 border-emerald-100 text-emerald-600',
    button: 'bg-emerald-600 hover:bg-emerald-700 text-white',
    fallback: HiCheck,
  },
  default: {
    icon: 'bg-slate-100 border-slate-200 text-slate-700',
    button: 'bg-slate-900 hover:bg-slate-800 text-white',
    fallback: HiShieldCheck,
  },
};

export const ConfirmModal = ({
  isOpen,
  title,
  message,
  confirmLabel = 'Confirm',
  cancelLabel = 'Cancel',
  onConfirm,
  onCancel,
  loading = false,
  variant = 'danger',
  icon: Icon,
}) => {
  useEscapeToClose(isOpen && !loading, onCancel);
  const style = confirmStyles[variant] || confirmStyles.default;
  const ModalIcon = Icon || style.fallback;

  return (
    <AnimatePresence>
      {isOpen && (
        <motion.div
          key="confirm-modal"
          initial={{ opacity: 0 }}
          animate={{ opacity: 1 }}
          exit={{ opacity: 0 }}
          className="fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-sm flex items-center justify-center p-4"
          role="dialog"
          aria-modal="true"
          aria-label={title}
        >
          <div className="absolute inset-0" onClick={loading ? undefined : onCancel} />
          <motion.div
            initial={{ scale: 0.96, opacity: 0, y: 8 }}
            animate={{ scale: 1, opacity: 1, y: 0 }}
            exit={{ scale: 0.96, opacity: 0, y: 8 }}
            transition={{ duration: 0.2, ease: EASE }}
            className="relative w-full max-w-md bg-white rounded-2xl border border-slate-200/80 p-6 shadow-xl z-10"
          >
            <button
              type="button"
              onClick={onCancel}
              disabled={loading}
              aria-label="Close dialog"
              className="absolute top-4 right-4 p-1.5 rounded-xl text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition-colors disabled:opacity-50"
            >
              <HiXMark className="w-5 h-5" />
            </button>

            <div
              className={`w-12 h-12 rounded-2xl flex items-center justify-center mx-auto mb-4 border shadow-2xs ${style.icon}`}
            >
              <ModalIcon className="w-6 h-6" />
            </div>

            <h3 className="text-lg font-bold text-slate-900 text-center tracking-tight">{title}</h3>
            <p className="text-sm text-slate-500 text-center mt-2 leading-relaxed">{message}</p>

            <div className="flex gap-3 mt-6">
              <Button
                variant="outline"
                className="flex-1 rounded-xl border-slate-200 text-slate-700 hover:bg-slate-50 font-medium"
                onClick={onCancel}
                disabled={loading}
              >
                {cancelLabel}
              </Button>
              <Button
                variant={variant === 'danger' ? 'destructive' : 'default'}
                className={`flex-1 rounded-xl font-medium shadow-2xs ${style.button}`}
                onClick={onConfirm}
                disabled={loading}
              >
                {loading ? (
<BrandLoader size="small" showText={false} />
                ) : (
                  confirmLabel
                )}
              </Button>
            </div>
          </motion.div>
        </motion.div>
      )}
    </AnimatePresence>
  );
};

export const AdminSkeleton = ({
  statCount = 4,
  statColumns = 'grid-cols-2 lg:grid-cols-4',
  rows = 5,
}) => (
  <div className="space-y-6">
    <div className="h-8 bg-slate-100 rounded-xl w-1/4 animate-pulse" />
    <div className={`grid ${statColumns} gap-4`}>
      {Array.from({ length: statCount }).map((_, i) => (
        <div key={i} className="bg-white rounded-2xl border border-slate-200/80 p-5 space-y-3">
          <div className="w-9 h-9 bg-slate-100 rounded-xl animate-pulse" />
          <div className="h-7 bg-slate-100 rounded-lg w-1/2 animate-pulse" />
          <div className="h-4 bg-slate-100 rounded-lg w-2/3 animate-pulse" />
        </div>
      ))}
    </div>
    <div className="bg-white rounded-2xl border border-slate-200/80 overflow-hidden p-4 space-y-3">
      {Array.from({ length: rows }).map((_, i) => (
        <div key={i} className="h-12 bg-slate-50 rounded-xl animate-pulse" />
      ))}
    </div>
  </div>
);

export const DetailSkeleton = ({ media = false }) => (
  <div className="space-y-6">
    <div className="h-8 bg-slate-100 rounded-xl w-1/4 animate-pulse" />
    <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <div className="lg:col-span-2 space-y-4">
        <div className="bg-white rounded-2xl border border-slate-200/80 p-6 space-y-4">
          {media && <div className="h-64 bg-slate-100 rounded-xl animate-pulse" />}
          <div className="h-6 bg-slate-100 rounded-lg w-1/3 animate-pulse" />
          <div className="grid grid-cols-2 gap-4">
            {Array.from({ length: 4 }).map((_, i) => (
              <div key={i} className="h-16 bg-slate-100 rounded-xl animate-pulse" />
            ))}
          </div>
        </div>
      </div>
      <div className="space-y-4">
        <div className="bg-white rounded-2xl border border-slate-200/80 p-6 space-y-4">
          <div className="h-6 bg-slate-100 rounded-lg w-1/2 animate-pulse" />
          <div className="h-4 bg-slate-100 rounded animate-pulse" />
        </div>
      </div>
    </div>
  </div>
);