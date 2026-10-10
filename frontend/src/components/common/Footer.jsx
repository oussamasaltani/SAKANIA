import { useState } from 'react';
import { Link } from 'react-router-dom';
import { FaFacebookF, FaInstagram, FaTwitter, FaLinkedinIn } from 'react-icons/fa';
import { HiMail } from 'react-icons/hi';
import toast from 'react-hot-toast';

const Footer = () => {
  const [email, setEmail] = useState('');

  const handleNewsletter = (e) => {
    e.preventDefault();
    if (email) {
      toast.success('Thanks for subscribing to our newsletter!');
      setEmail('');
    }
  };

  return (
    <footer className="bg-white border-t border-gray-200/70 text-gray-600">
      <div className="h-px bg-gradient-to-r from-transparent via-brand/40 to-transparent" />
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-10">
          <div className="lg:col-span-1">
            <img
              src="/Logo-sakania.png"
              alt="SAKANIA"
              className="mb-4 h-12 sm:h-14 w-auto object-contain"
            />

            <p className="text-sm leading-relaxed mb-6 text-gray-500">
              Your trusted platform for property rentals in Morocco. Find your perfect home with verified listings and secure transactions.
            </p>
            <div className="flex gap-2.5">
              {[FaFacebookF, FaInstagram, FaTwitter, FaLinkedinIn].map((Icon, i) => (
                <a
                  key={i}
                  href="#"
                  className="w-10 h-10 bg-gray-100 text-gray-500 hover:bg-brand-600 hover:text-white rounded-xl flex items-center justify-center transition-all duration-300 hover:shadow-lg hover:shadow-brand/30 hover:-translate-y-0.5"                >
                  <Icon className="w-4 h-4" />
                </a>
              ))}
            </div>
          </div>

          <div>
            <h3 className="text-sm font-semibold text-gray-900 mb-4 uppercase tracking-wider">Quick Links</h3>
            <ul className="space-y-2.5">
              {[{ label: 'Home', to: '/' }, { label: 'Properties', to: '/properties' }, { label: 'About Us', to: '/' }, { label: 'Become an Owner', to: '/register' }].map((link) => (
                <li key={link.label}>
                  <Link to={link.to} className="text-sm text-gray-500 hover:text-brand hover:translate-x-0.5 inline-block transition-all">{link.label}</Link>
                </li>
              ))}
            </ul>
          </div>

          <div>
            <h3 className="text-sm font-semibold text-gray-900 mb-4 uppercase tracking-wider">Support</h3>
            <ul className="space-y-2.5">
              {[{ label: 'FAQ', to: '/faq' }, { label: 'Contact Us', to: '/contact' }, { label: 'Privacy Policy', to: '/privacy' }, { label: 'Terms of Service', to: '/terms' }].map((link) => (
                <li key={link.label}>
                  <Link to={link.to} className="text-sm text-gray-500 hover:text-brand hover:translate-x-0.5 inline-block transition-all">{link.label}</Link>
                </li>
              ))}
            </ul>
          </div>

          <div>
            <h3 className="text-sm font-semibold text-gray-900 mb-4 uppercase tracking-wider">Newsletter</h3>
            <p className="text-sm mb-4 text-gray-500">Get the latest properties and updates delivered to your inbox.</p>
            <form onSubmit={handleNewsletter} className="flex flex-col gap-2.5">
              <div className="relative">
                <HiMail className="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
                <input
                  type="email"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  placeholder="Your email"
                  className="w-full pl-10 pr-4 py-2.5 bg-gray-50 border border-gray-200 rounded-xl text-sm text-gray-900 placeholder:text-gray-400 focus:outline-none focus:bg-white focus:border-brand/40 focus:ring-4 focus:ring-brand/10 transition-all"
                />
              </div>
              <button
                type="submit"
                className="w-full py-2.5 bg-brand-gradient text-white text-sm font-semibold rounded-xl shadow-lg shadow-brand/25 hover:shadow-xl hover:shadow-brand/35 hover:brightness-[1.04] transition-all"
              >
                Subscribe
              </button>
            </form>
          </div>
        </div>
      </div>

      <div className="border-t border-gray-100">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 flex flex-col sm:flex-row items-center justify-between gap-4">
          <p className="text-sm text-gray-400">&copy; {new Date().getFullYear()} SAKANIA. All rights reserved.</p>
          <div className="flex gap-5 text-sm text-gray-400">
            <Link to="/privacy" className="hover:text-brand transition-colors">Privacy</Link>
            <Link to="/terms" className="hover:text-brand transition-colors">Terms</Link>
          </div>
        </div>
      </div>
    </footer>
  );
};

export default Footer;
