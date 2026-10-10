import { useState, useEffect, useRef, useCallback } from 'react';
import { AnimatePresence, motion } from 'framer-motion';
import { getNotifications, getUnreadCount, markAsRead, markAllAsRead } from '../../services/notificationService';
import {
  HiBell, HiCheck, HiCalendar, HiDocumentText, HiCreditCard, HiHome, HiSparkles,
} from 'react-icons/hi';
import toast from 'react-hot-toast';
import BrandLoader from './BrandLoader';

const POLL_INTERVAL_MS = 30000;

const typeStyles = {
  reservation: { Icon: HiCalendar, tint: 'bg-blue-50 text-blue-600' },
  contract: { Icon: HiDocumentText, tint: 'bg-purple-50 text-purple-600' },
  payment: { Icon: HiCreditCard, tint: 'bg-emerald-50 text-emerald-600' },
  property: { Icon: HiHome, tint: 'bg-amber-50 text-amber-600' },
};

const styleFor = (type) => typeStyles[type] || { Icon: HiSparkles, tint: 'bg-brand-soft text-brand' };

const formatRelativeTime = (dateStr) => {
  if (!dateStr) return '';
  const now = new Date();
  const date = new Date(dateStr);
  const diffMs = now - date;
  const diffSec = Math.floor(diffMs / 1000);
  const diffMin = Math.floor(diffSec / 60);
  const diffHr = Math.floor(diffMin / 60);
  const diffDay = Math.floor(diffHr / 24);

  if (diffSec < 60) return 'Just now';
  if (diffMin < 60) return `${diffMin}m ago`;
  if (diffHr < 24) return `${diffHr}h ago`;
  if (diffDay < 7) return `${diffDay}d ago`;
  return date.toLocaleDateString();
};

const NotificationDropdown = () => {
  const [isOpen, setIsOpen] = useState(false);
  const [notifications, setNotifications] = useState([]);
  const [unreadCount, setUnreadCount] = useState(0);
  const [loading, setLoading] = useState(false);
  const dropdownRef = useRef(null);
  const isOpenRef = useRef(false);
  const unreadCountRef = useRef(0);

  const fetchNotifications = useCallback(() => {
    setLoading(true);
    getNotifications()
      .then((res) => {
        const payload = res.data?.data || res.data || [];
        setNotifications(Array.isArray(payload) ? payload : []);
      })
      .catch(() => toast.error('Failed to load notifications'))
      .finally(() => setLoading(false));
  }, []);

  const fetchUnreadCount = useCallback(() => {
    getUnreadCount()
      .then((res) => {
        const next = res.data?.count ?? res.data?.unread_count ?? 0;
        const previous = unreadCountRef.current;
        unreadCountRef.current = next;
        setUnreadCount(next);

        // A notification raised while the panel is already open (a
        // reservation accepted, a payment processed, ...) has to appear
        // straight away instead of waiting for the next reopen.
        if (isOpenRef.current && next !== previous) {
          fetchNotifications();
        }
      })
      .catch(() => {});
  }, [fetchNotifications]);

  useEffect(() => {
    isOpenRef.current = isOpen;
  }, [isOpen]);

  useEffect(() => {
    fetchUnreadCount();
    const interval = setInterval(fetchUnreadCount, POLL_INTERVAL_MS);

    // The events that create notifications happen on another user's screen,
    // so a returning tab is a useful moment to resynchronise.
    const handleFocus = () => {
      fetchUnreadCount();
      if (isOpenRef.current) fetchNotifications();
    };
    window.addEventListener('focus', handleFocus);

    return () => {
      clearInterval(interval);
      window.removeEventListener('focus', handleFocus);
    };
  }, [fetchUnreadCount, fetchNotifications]);

  useEffect(() => {
    if (isOpen) {
      fetchNotifications();
    }
  }, [isOpen, fetchNotifications]);

  useEffect(() => {
    const handleClickOutside = (e) => {
      if (dropdownRef.current && !dropdownRef.current.contains(e.target)) {
        setIsOpen(false);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  const handleMarkAsRead = async (id) => {
    try {
      await markAsRead(id);
      setNotifications((prev) => prev.map((n) => n.id === id ? { ...n, is_read: true } : n));
      setUnreadCount((prev) => {
        const next = Math.max(0, prev - 1);
        unreadCountRef.current = next;
        return next;
      });
    } catch {
      toast.error('Failed to mark as read');
    }
  };

  const handleMarkAllAsRead = async () => {
    try {
      await markAllAsRead();
      setNotifications((prev) => prev.map((n) => ({ ...n, is_read: true })));
      unreadCountRef.current = 0;
      setUnreadCount(0);
      toast.success('All notifications marked as read');
    } catch {
      toast.error('Failed to mark all as read');
    }
  };

  return (
    <div className="relative" ref={dropdownRef}>
      <button
        onClick={() => setIsOpen(!isOpen)}
        className="relative p-2.5 hover:bg-brand-soft rounded-xl transition-colors group"
        aria-label="Notifications"
      >
        <HiBell className={`w-5 h-5 transition-colors ${isOpen || unreadCount > 0 ? 'text-brand' : 'text-gray-500 group-hover:text-brand'}`} />
        {unreadCount > 0 && (
          <span className="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] bg-brand-gradient text-white text-[10px] font-bold rounded-full flex items-center justify-center px-1 ring-2 ring-white">
            {unreadCount > 99 ? '99+' : unreadCount}
          </span>
        )}
      </button>

      <AnimatePresence>
        {isOpen && (
          <motion.div
            initial={{ opacity: 0, scale: 0.96, y: -4 }}
            animate={{ opacity: 1, scale: 1, y: 0 }}
            exit={{ opacity: 0, scale: 0.96, y: -4 }}
            transition={{ duration: 0.18, ease: [0.16, 1, 0.3, 1] }}
            style={{ transformOrigin: 'top right' }}
            className="absolute right-0 mt-2 w-[22rem] max-w-[calc(100vw-2rem)] bg-white rounded-2xl shadow-2xl shadow-gray-900/10 border border-gray-200/70 overflow-hidden z-50"
          >
            <div className="flex items-center justify-between px-4 py-3.5 border-b border-gray-100 bg-gray-50/50">
              <h3 className="font-semibold text-gray-900 text-sm">Notifications</h3>
              {unreadCount > 0 && (
                <button
                  onClick={handleMarkAllAsRead}
                  className="text-xs text-brand hover:text-brand-dark font-semibold flex items-center gap-1 hover:bg-brand-soft px-2 py-1 rounded-lg transition-colors"
                >
                  <HiCheck className="w-3.5 h-3.5" />
                  Mark all read
                </button>
              )}
            </div>

            <div className="max-h-96 overflow-y-auto">
              {loading ? (
                <div className="p-8 flex items-center justify-center">
                  <BrandLoader size="small" showText={false} />
                </div>
              ) : notifications.length === 0 ? (
                <div className="p-8 text-center">
                  <div className="w-12 h-12 rounded-2xl bg-gray-100 flex items-center justify-center mx-auto mb-3">
                    <HiBell className="w-6 h-6 text-gray-400" />
                  </div>
                  <p className="text-sm font-medium text-gray-600">No notifications yet</p>
                  <p className="text-xs text-gray-400 mt-1">We&apos;ll let you know when something happens.</p>
                </div>
              ) : (
                notifications.map((n) => {
                  const { Icon, tint } = styleFor(n.type);
                  return (
                    <button
                      key={n.id}
                      onClick={() => {
                        if (!n.is_read) handleMarkAsRead(n.id);
                      }}
                      className={`w-full text-left px-4 py-3 flex items-start gap-3 hover:bg-gray-50 transition-colors border-b border-gray-50 last:border-0 ${
                        !n.is_read ? 'bg-brand-soft/40' : ''
                      }`}
                    >
                      <div className={`w-9 h-9 rounded-xl flex items-center justify-center shrink-0 ${tint}`}>
                        <Icon className="w-4 h-4" />
                      </div>
                      <div className="min-w-0 flex-1">
                        <p className={`text-sm leading-snug ${!n.is_read ? 'font-semibold text-gray-900' : 'font-medium text-gray-600'}`}>
                          {n.title || 'Notification'}
                        </p>
                        <p className="text-xs text-gray-500 mt-0.5 line-clamp-2">
                          {n.message || n.text || ''}
                        </p>
                        <p className="text-xs text-gray-400 mt-1">{formatRelativeTime(n.created_at)}</p>
                      </div>
                      <div className={`w-2 h-2 rounded-full mt-1.5 shrink-0 ring-2 ring-white ${!n.is_read ? 'bg-brand shadow-sm shadow-brand/40' : 'bg-transparent'}`}></div>
                    </button>
                  );
                })
              )}
            </div>
          </motion.div>
        )}
      </AnimatePresence>
    </div>
  );
};

export default NotificationDropdown;
