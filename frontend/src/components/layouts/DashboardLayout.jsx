import { useState, useEffect } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { AnimatePresence, motion } from 'framer-motion';
import { useAuth } from '../../contexts/AuthContext';
import { getUnreadCount } from '../../services/messageService';
import { getImageUrl, getInitials } from '../../utils/getImageUrl';
import NotificationDropdown from '../common/NotificationDropdown';
import {
  HiViewGrid, HiUsers, HiHome, HiCalendar, HiDocumentText, HiCreditCard,
  HiPlus, HiSearch, HiMenu, HiX, HiLogout, HiUser, HiChevronDown, HiChatAlt, HiHeart,
} from 'react-icons/hi';

const adminLinks = [
  { label: 'Dashboard', path: '/admin', icon: HiViewGrid },
  { label: 'Users', path: '/admin/users', icon: HiUsers },
  { label: 'Properties', path: '/admin/properties', icon: HiHome },
  { label: 'Reservations', path: '/admin/reservations', icon: HiCalendar },
  { label: 'Contracts', path: '/admin/contracts', icon: HiDocumentText },
  { label: 'Payments', path: '/admin/payments', icon: HiCreditCard },
];

const ownerLinks = [
  { label: 'Dashboard', path: '/owner', icon: HiViewGrid },
  { label: 'My Properties', path: '/owner/properties', icon: HiHome },
  { label: 'Add Property', path: '/owner/properties/add', icon: HiPlus },
  { label: 'Reservations', path: '/owner/reservations', icon: HiCalendar },
  { label: 'Contracts', path: '/owner/contracts', icon: HiDocumentText },
  { label: 'Payments', path: '/owner/payments', icon: HiCreditCard },
  { label: 'Messages', path: '/owner/messages', icon: HiChatAlt, badge: true },
];

const tenantLinks = [
  { label: 'Dashboard', path: '/tenant', icon: HiViewGrid },
  { label: 'Favorites', path: '/tenant/favorites', icon: HiHeart },
  { label: 'My Reservations', path: '/tenant/reservations', icon: HiCalendar },
  { label: 'My Contracts', path: '/tenant/contracts', icon: HiDocumentText },
  { label: 'My Payments', path: '/tenant/payments', icon: HiCreditCard },
  { label: 'Messages', path: '/tenant/messages', icon: HiChatAlt, badge: true },
];

const roleBadgeColors = {
  admin: 'bg-purple-100 text-purple-700 ring-purple-200/60',
  owner: 'bg-blue-100 text-blue-700 ring-blue-200/60',
  tenant: 'bg-green-100 text-green-700 ring-green-200/60',
};

const DashboardLayout = ({ children }) => {
  const { user, logout } = useAuth();
  const location = useLocation();
  const navigate = useNavigate();
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const [userDropdownOpen, setUserDropdownOpen] = useState(false);
  const [unreadMessages, setUnreadMessages] = useState(0);

  // The sidebar badge is a pointer to the inbox, so it polls like the
  // notification bell does and refreshes whenever the route changes.
  useEffect(() => {
    let cancelled = false;

    const load = () => {
      getUnreadCount()
        .then((response) => {
          if (!cancelled) setUnreadMessages(response.data.unread_count || 0);
        })
        .catch(() => { });
    };

    load();
    const timer = setInterval(load, 30000);

    return () => {
      cancelled = true;
      clearInterval(timer);
    };
  }, [location.pathname]);

  const links = user?.role === 'admin' ? adminLinks : user?.role === 'owner' ? ownerLinks : tenantLinks;
  const roleBadge = roleBadgeColors[user?.role] || 'bg-gray-100 text-gray-700 ring-gray-200/60';

  // The avatar is stored as a path relative to public/, so it has to be rooted
  // before the browser can load it. refreshUser() in AuthContext keeps these in
  // step with a picture uploaded on the profile page.
  const avatarSrc = getImageUrl(user);
  const initials = getInitials(user?.full_name || user?.name);

  const isActive = (path) => {
    if (path === '/admin' || path === '/owner' || path === '/tenant') {
      return location.pathname === path;
    }
    return location.pathname.startsWith(path);
  };

  const handleLogout = async () => {
    await logout();
    navigate('/login');
  };

  return (
    <div className="min-h-screen bg-gray-50/70 flex">
      <AnimatePresence>
        {sidebarOpen && (
          <motion.div
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            transition={{ duration: 0.2 }}
            className="fixed inset-0 z-40 bg-gray-900/40 backdrop-blur-sm lg:hidden"
            onClick={() => setSidebarOpen(false)}
          />
        )}
      </AnimatePresence>

      <aside
        className={`fixed inset-y-0 left-0 z-50 w-[240px] bg-white/98 backdrop-blur-2xl border-r border-gray-100 shadow-[0_0_0_1px_rgba(15,23,42,0.02),_0_10px_80px_-20px_rgba(15,23,42,0.25)] transform transition-transform duration-300 ease-out lg:translate-x-0 ${sidebarOpen ? 'translate-x-0' : '-translate-x-full'} flex flex-col`}
      >
        <div className="px-5 py-5 flex items-center justify-center relative border-b border-gray-100">
          <Link to="/" className="flex flex-col items-center text-center gap-1 group">
            <img
              src="/Logo-sakania.png"
              alt="Sakania"
              className="h-14 md:h-16 w-auto max-w-[280px] object-contain transition-all duration-300 ease-out group-hover:scale-[1.03] group-hover:opacity-95"
            />
            <span className="block text-[10px] font-semibold uppercase tracking-[0.16em] text-gray-400/90 -mt-2">
              {user?.role || 'User'} workspace
            </span>
          </Link>
          <button
            onClick={() => setSidebarOpen(false)}
            className="absolute right-4 p-2 hover:bg-gray-100/90 rounded-xl lg:hidden active:scale-[0.98] transition-all duration-200 ease-out"
          >
            <HiX className="w-5 h-5 text-gray-600" />
          </button>
        </div>

        <nav className="flex-1 overflow-y-auto px-3 py-4 space-y-1">
          <p className="px-3 mb-2 text-[11px] font-semibold uppercase tracking-wider text-gray-400">Menu</p>
          {links.map((link) => {
            const Icon = link.icon;
            const active = isActive(link.path);
            return (
              <Link
                key={link.path}
                to={link.path}
                onClick={() => setSidebarOpen(false)}
                className={`relative group flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm transition-all duration-200 ease-out overflow-hidden ${active ? 'text-orange-700 font-semibold shadow-sm' : 'text-gray-600 hover:bg-gray-50/90 hover:text-gray-900'
                  }`}
              >
                {active && (
                  <motion.span
                    layoutId="sidebar-active"
                    className="absolute inset-0 rounded-xl bg-gradient-to-r from-orange-50/90 to-orange-50/70 ring-1 ring-inset ring-orange-500/10"
                    style={{ zIndex: 0 }}
                    transition={{ type: 'spring', stiffness: 420, damping: 34 }}
                  />
                )}
                <Icon className={`relative w-5 h-5 transition-colors duration-200 ${active ? 'text-orange-500' : 'text-gray-400 group-hover:text-gray-500'}`} style={{ zIndex: 1 }} />
                <span className="relative flex-1 tracking-tight" style={{ zIndex: 1 }}>{link.label}</span>
                {link.badge && unreadMessages > 0 && (
                  <span
                    className="relative inline-flex items-center justify-center min-w-5 h-5 px-1.5 rounded-full bg-gradient-to-r from-orange-500 to-orange-600 text-white text-[10px] font-bold shadow-sm shadow-orange-500/20"
                    style={{ zIndex: 1 }}
                  >
                    {unreadMessages > 99 ? '99+' : unreadMessages}
                  </span>
                )}
              </Link>
            );
          })}
        </nav>

        <div className="p-3 border-t border-gray-100">
          <button
            onClick={handleLogout}
            className="flex items-center gap-3 w-full px-3 py-2.5 rounded-xl text-sm font-semibold text-gray-600 hover:bg-red-50/90 hover:text-red-600 active:scale-[0.995] transition-all duration-200 ease-out"
          >
            <HiLogout className="w-5 h-5" />
            Logout
          </button>
        </div>
      </aside>

      <div className="flex-1 lg:ml-[240px] flex flex-col min-h-screen">
        <header className="sticky top-0 z-30 bg-white/90 backdrop-blur-2xl border-b border-gray-100 shadow-[0_1px_3px_rgba(15,23,42,0.03)]">
          <div className="flex items-center justify-between px-4 sm:px-6 py-3">
            <div className="flex items-center gap-3">
              <button
                onClick={() => setSidebarOpen(true)}
                className="p-2.5 hover:bg-gray-100/90 rounded-xl lg:hidden active:scale-[0.98] transition-all duration-200 ease-out"
              >
                <HiMenu className="w-5 h-5 text-gray-700" />
              </button>
              <div className="relative hidden sm:block">
                <HiSearch className="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
                <input
                  type="text"
                  placeholder="Search..."
                  className="pl-10 pr-4 py-2.5 bg-gray-50/80 border border-gray-200/80 rounded-2xl text-sm placeholder:text-gray-400 focus:outline-none focus:bg-white focus:border-orange-400/40 focus:ring-4 focus:ring-orange-500/10 transition-all duration-200 ease-out w-64 shadow-sm hover:shadow-md focus:shadow-md"
                />
              </div>
            </div>

            <div className="flex items-center gap-2">
              <NotificationDropdown />

              <div className="relative">
                <button
                  onClick={() => setUserDropdownOpen(!userDropdownOpen)}
                  className="flex items-center gap-2.5 p-1.5 pr-3 bg-white/80 backdrop-blur-sm border border-transparent hover:border-gray-200/80 rounded-2xl hover:bg-gray-50/90 shadow-sm hover:shadow-md active:scale-[0.995] transition-all duration-200 ease-out"
                >
                  <div className="w-9 h-9 rounded-full bg-brand-gradient flex items-center justify-center overflow-hidden shadow-md shadow-brand/25">
                    {avatarSrc ? (
                      <img src={avatarSrc} alt="" className="w-full h-full object-cover" />
                    ) : (
                      <span className="text-white text-sm font-semibold">{initials}</span>
                    )}
                  </div>
                  <div className="hidden sm:block text-left">
                    <p className="text-sm font-semibold text-gray-900 leading-tight">{user?.full_name || user?.name || 'User'}</p>
                    <p className="text-[10px] text-gray-400 capitalize font-medium">{user?.role || 'Role'}</p>
                  </div>
                  <HiChevronDown
                    className={`w-4 h-4 text-gray-400 hidden sm:block transition-transform duration-200 ${userDropdownOpen ? 'rotate-180' : ''}`}
                  />
                </button>

                <AnimatePresence>
                  {userDropdownOpen && (
                    <>
                      <div className="fixed inset-0 z-40" onClick={() => setUserDropdownOpen(false)} />
                      <motion.div
                        initial={{ opacity: 0, scale: 0.96, y: -4 }}
                        animate={{ opacity: 1, scale: 1, y: 0 }}
                        exit={{ opacity: 0, scale: 0.96, y: -4 }}
                        transition={{ duration: 0.18, ease: [0.16, 1, 0.3, 1] }}
                        style={{ transformOrigin: 'top right' }}
                        className="absolute right-0 mt-2 w-64 bg-white/98 backdrop-blur-2xl rounded-2xl shadow-[0_10px_80px_-20px_rgba(15,23,42,0.25)] border border-gray-200/80 overflow-hidden z-50 ring-1 ring-black/5"
                      >
                        <div className="px-4 py-3.5 border-b border-gray-100 bg-gray-50/50">
                          <p className="font-semibold text-gray-900 text-sm">{user?.full_name || user?.name || 'User'}</p>
                          <p className="text-xs text-gray-500 mt-0.5 truncate">{user?.email || ''}</p>
                          <span className={`inline-flex items-center mt-2 px-2.5 py-0.5 rounded-full text-[10px] font-semibold ring-1 ring-inset capitalize ${roleBadge}`}>
                            {user?.role?.charAt(0).toUpperCase() + user?.role?.slice(1) || 'Role'}
                          </span>
                        </div>
                        <div className="py-1.5">
                          <Link
                            to={`/${user?.role}/profile`}
                            onClick={() => setUserDropdownOpen(false)}
                            className="flex items-center gap-2.5 mx-1.5 px-3 py-2.5 rounded-xl text-sm font-semibold text-gray-700 hover:bg-orange-50/80 hover:text-orange-600 transition-all duration-200 ease-out"
                          >
                            <HiUser className="w-4 h-4" />
                            Profile
                          </Link>
                          <button
                            onClick={() => { setUserDropdownOpen(false); handleLogout(); }}
                            className="flex items-center gap-2.5 w-full mx-1.5 px-3 py-2.5 rounded-xl text-sm font-semibold text-red-600 hover:bg-red-50/90 transition-all duration-200 ease-out"
                          >
                            <HiLogout className="w-4 h-4" />
                            Logout
                          </button>
                        </div>
                      </motion.div>
                    </>
                  )}
                </AnimatePresence>
              </div>
            </div>
          </div>
        </header>

        <main className="flex-1 bg-gradient-to-b from-gray-50/70 to-white">
          {children}
        </main>
      </div>
    </div>
  );
};

export default DashboardLayout;
