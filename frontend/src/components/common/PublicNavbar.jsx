import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { AnimatePresence, motion } from 'framer-motion';
import { useAuth } from '../../contexts/AuthContext';
import { HiMenu, HiX, HiUser, HiOutlineLogout, HiChevronDown } from 'react-icons/hi';
import { BsChatDots } from 'react-icons/bs';
import UserAvatar from './UserAvatar';

const PublicNavbar = () => {
  const { user, logout } = useAuth();
  const navigate = useNavigate();
  const [mobileOpen, setMobileOpen] = useState(false);
  const [dropdownOpen, setDropdownOpen] = useState(false);

  const handleLogout = async () => {
    await logout();
    navigate('/');
    setDropdownOpen(false);
  };

  const roleBadge = (role) => {
    const colors = {
      tenant: 'bg-blue-50 text-blue-700 ring-blue-200/60',
      owner: 'bg-emerald-50 text-emerald-700 ring-emerald-200/60',
      admin: 'bg-purple-50 text-purple-700 ring-purple-200/60',
    };
    return (
      <span className={`text-[10px] font-semibold px-2 py-0.5 rounded-full capitalize ring-1 ring-inset ${colors[role] || 'bg-gray-100 text-gray-700 ring-gray-200/60'}`}>
        {role}
      </span>
    );
  };

  const dashboardLink = () => {
    if (!user) return null;
    const map = { tenant: '/tenant', owner: '/owner', admin: '/admin' };
    return map[user.role];
  };

  const navLinkClass =
    'relative px-1.5 text-[15px] font-semibold tracking-tight text-gray-600 hover:text-gray-900 transition-colors duration-300 ease-out after:absolute after:-bottom-1.5 after:left-1/2 after:h-0.5 after:w-0 after:-translate-x-1/2 after:rounded-full after:bg-gradient-to-r after:from-orange-500 after:to-orange-400 after:content-[""] hover:after:w-[70%] after:transition-all after:duration-300 after:ease-out';

  return (
    <nav className="sticky top-0 z-50 bg-white/90 backdrop-blur-2xl border-b border-gray-100 shadow-[0_1px_3px_rgba(15,23,42,0.03),_0_10px_60px_-20px_rgba(15,23,42,0.15)]">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="flex items-center justify-between h-16">
          <Link to="/" className="flex items-center gap-2.5 shrink-0 group">
            <img
              src="/Logo-sakania.png"
              alt="Sakania"
              className="h-15 md:h-19 w-auto max-w-[280px] object-contain transition-all duration-300 ease-out group-hover:opacity-95"
            />
          </Link>

          <div className="hidden md:flex items-center gap-9">
            <Link to="/" className={navLinkClass}>Home</Link>
            <Link to="/properties" className={navLinkClass}>Properties</Link>
          </div>

          <div className="hidden md:flex items-center gap-3">
            {user ? (
              <div className="relative">
                <button
                  onClick={() => setDropdownOpen(!dropdownOpen)}
                  className="flex items-center gap-2 pl-1.5 pr-3 py-1.5 rounded-2xl bg-white/80 backdrop-blur-sm border border-gray-200/70 hover:border-gray-300 shadow-sm hover:shadow-md transition-all duration-200 ease-out active:scale-[0.995]"
                >
                  <div className="w-8 h-8 bg-brand-gradient rounded-full flex items-center justify-center overflow-hidden shadow-md shadow-brand/25">
                    <UserAvatar user={user} className="w-full h-full text-white text-sm" />
                  </div>
                  <span className="text-sm font-medium text-gray-700 max-w-[100px] truncate">{user.full_name || user.name}</span>
                  {roleBadge(user.role)}
                  <HiChevronDown className={`w-4 h-4 text-gray-400 transition-transform duration-200 ${dropdownOpen ? 'rotate-180' : ''}`} />
                </button>

                <AnimatePresence>
                  {dropdownOpen && (
                    <motion.div
                      initial={{ opacity: 0, scale: 0.96, y: -4 }}
                      animate={{ opacity: 1, scale: 1, y: 0 }}
                      exit={{ opacity: 0, scale: 0.96, y: -4 }}
                      transition={{ duration: 0.18, ease: [0.16, 1, 0.3, 1] }}
                      style={{ transformOrigin: 'top right' }}
                       className="absolute right-0 mt-2 w-64 bg-white/98 backdrop-blur-2xl rounded-2xl shadow-[0_10px_80px_-20px_rgba(15,23,42,0.25)] border border-gray-200/80 py-1.5 z-50 overflow-hidden ring-1 ring-black/5"
                     >
                      <div className="px-4 py-3 border-b border-gray-100 bg-gray-50/50">
                        <p className="text-sm font-semibold text-gray-900 truncate">{user.full_name || user.name}</p>
                        <p className="text-xs text-gray-500 truncate">{user.email}</p>
                      </div>
                      <div className="p-1.5">
                        <Link
                          to={dashboardLink()}
                          onClick={() => setDropdownOpen(false)}
                           className="flex items-center gap-2.5 px-3 py-2.5 mx-1 rounded-xl text-sm font-semibold text-gray-700 hover:bg-orange-50/80 hover:text-orange-600 transition-all duration-200 ease-out"
                         >
                           <HiUser className="w-4 h-4" />
                           Dashboard
                         </Link>
                         <button
                           onClick={handleLogout}
                           className="flex items-center gap-2.5 w-full px-3 py-2.5 mx-1 rounded-xl text-sm font-semibold text-red-600 hover:bg-red-50/90 transition-all duration-200 ease-out"
                         >
                          <HiOutlineLogout className="w-4 h-4" />
                          Logout
                        </button>
                      </div>
                    </motion.div>
                  )}
                </AnimatePresence>
              </div>
            ) : (
              <>
                <Link
                  to="/login"
                 className="h-10 px-5 inline-flex items-center rounded-2xl text-sm font-semibold text-gray-700 border border-gray-200/80 bg-white shadow-sm hover:bg-gray-50 hover:border-gray-300 hover:shadow-md active:scale-[0.995] transition-all duration-200 ease-out"
               >
                 Login
               </Link>
               <Link
                 to="/register"
                 className="h-10 px-5 inline-flex items-center rounded-2xl text-sm font-semibold text-white bg-gradient-to-r from-orange-500 via-orange-500 to-orange-600 shadow-lg shadow-orange-500/20 hover:shadow-xl hover:shadow-orange-500/30 hover:brightness-[1.03] active:scale-[0.995] transition-all duration-200 ease-out"
               >
                  Register
                </Link>
              </>
            )}
          </div>

           <button
             onClick={() => setMobileOpen(!mobileOpen)}
             className="md:hidden p-2.5 rounded-xl hover:bg-gray-100/90 active:scale-[0.98] transition-all duration-200 ease-out"
           >
            {mobileOpen ? <HiX className="w-6 h-6 text-gray-700" /> : <HiMenu className="w-6 h-6 text-gray-700" />}
          </button>
        </div>
      </div>

      <AnimatePresence>
        {mobileOpen && (
          <motion.div
            initial={{ opacity: 0, height: 0 }}
            animate={{ opacity: 1, height: 'auto' }}
            exit={{ opacity: 0, height: 0 }}
            transition={{ duration: 0.25, ease: [0.16, 1, 0.3, 1] }}
            className="md:hidden overflow-hidden border-t border-gray-100 bg-white/98 backdrop-blur-2xl"
          >
            <div className="px-4 py-3 space-y-1">
               <Link to="/" onClick={() => setMobileOpen(false)} className="block px-3 py-2.5 rounded-xl text-gray-700 hover:bg-orange-50/80 hover:text-orange-600 font-semibold transition-all duration-200 ease-out">
                 Home
               </Link>
               <Link to="/properties" onClick={() => setMobileOpen(false)} className="block px-3 py-2.5 rounded-xl text-gray-700 hover:bg-orange-50/80 hover:text-orange-600 font-semibold transition-all duration-200 ease-out">
                 Properties
               </Link>

              {user ? (
                <>
                  <div className="border-t border-gray-100 pt-2 mt-2">
                    <div className="flex items-center gap-2.5 px-3 py-2">
                      <div className="w-9 h-9 bg-brand-gradient rounded-full flex items-center justify-center overflow-hidden shadow-md shadow-brand/25">
                        <UserAvatar user={user} className="w-full h-full text-white text-sm" />
                      </div>
                      <div>
                        <p className="text-sm font-semibold text-gray-900">{user.full_name || user.name}</p>
                        {roleBadge(user.role)}
                      </div>
                    </div>
                     <Link
                       to={dashboardLink()}
                       onClick={() => setMobileOpen(false)}
                       className="block px-3 py-2.5 rounded-xl hover:bg-orange-50/80 hover:text-orange-600 text-sm font-semibold text-gray-700 transition-all duration-200 ease-out"
                     >
                       Dashboard
                     </Link>
                     <button
                       onClick={() => { handleLogout(); setMobileOpen(false); }}
                       className="w-full text-left px-3 py-2.5 rounded-xl text-red-600 hover:bg-red-50/90 text-sm font-semibold transition-all duration-200 ease-out"
                     >
                      Logout
                    </button>
                  </div>
                </>
              ) : (
                <div className="border-t border-gray-100 pt-2 mt-2 flex flex-col gap-2 pb-1">
                   <Link to="/login" onClick={() => setMobileOpen(false)} className="block text-center px-4 py-2.5 text-sm font-semibold text-gray-700 border border-gray-200 rounded-2xl hover:bg-gray-50 active:scale-[0.995] transition-all duration-200 ease-out">
                     Login
                   </Link>
                   <Link to="/register" onClick={() => setMobileOpen(false)} className="block text-center px-4 py-2.5 text-sm font-semibold text-white bg-gradient-to-r from-orange-500 to-orange-600 rounded-2xl shadow-lg shadow-orange-500/20 active:scale-[0.995] transition-all duration-200 ease-out">
                     Register
                   </Link>
                </div>
              )}
            </div>
          </motion.div>
        )}
      </AnimatePresence>

    </nav>
  );
};

export default PublicNavbar;
