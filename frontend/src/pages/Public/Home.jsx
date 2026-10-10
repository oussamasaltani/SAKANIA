import { useState, useEffect, useRef } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { getFeaturedProperties, peekFeaturedProperties } from '../../services/propertyService';
import { getImageUrl } from '../../utils/getImageUrl';
import PublicNavbar from '../../components/common/PublicNavbar';
import Footer from '../../components/common/Footer';
import {
  HiArrowRight,
  HiBuildingOffice2,
  HiCheck,
  HiCheckBadge,
  HiCreditCard,
  HiDocumentText,
  HiHome,
  HiKey,
  HiMagnifyingGlass,
  HiMapPin,
  HiMinus,
  HiPlus,
  HiShieldCheck,
  HiSparkles,
  HiStar,
  HiChevronRight,
  HiUserGroup,
  HiBolt,
  HiArrowUpRight,
} from 'react-icons/hi2';
import { motion, AnimatePresence, useReducedMotion } from 'framer-motion';
import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';

const EASE = [0.16, 1, 0.3, 1];

/* ---------- Static Content ---------- */

const CITIES = ['Casablanca', 'Marrakech', 'Rabat', 'Tangier', 'Agadir'];

const STATS = [
  { value: 1000, suffix: '+', label: 'Verified Listings', desc: 'Curated homes ready for move-in' },
  { value: 500, suffix: '+', label: 'Active Tenants', desc: 'Trusting SAKANIA monthly' },
  { value: 200, suffix: '+', label: 'Verified Owners', desc: 'Direct owner relationships' },
  { value: 98, suffix: '%', label: 'Satisfaction Rate', desc: 'Based on post-lease reviews' },
];

const STEPS = [
  {
    icon: HiMagnifyingGlass,
    badge: '01. Search',
    title: 'Smart Discovery',
    desc: 'Filter verified listings by exact city, neighborhood, budget, and desired lifestyle features with zero spam.',
  },
  {
    icon: HiHome,
    badge: '02. Reserve',
    title: 'Direct Reservation',
    desc: 'Submit lease requests with 100% transparent pricing directly to identity-verified property owners.',
  },
  {
    icon: HiKey,
    badge: '03. Move In',
    title: 'Digital Key Handover',
    desc: 'Sign legally binding digital contracts, complete encrypted payments, and get keys handed over fast.',
  },
];

const FEATURES = [
  {
    icon: HiShieldCheck,
    title: '100% Verified Property Listings',
    desc: 'Every home goes through physical and document checks by our local team before publishing. Zero fake ads.',
    wide: true,
    dark: true,
    tag: 'Core Security',
  },
  {
    icon: HiSparkles,
    title: 'AI Smart Matchmaking',
    desc: 'Instant recommendations based on your budget, commute distance, and non-negotiable amenities.',
    tag: 'Next-Gen Tech',
  },
  {
    icon: HiCreditCard,
    title: 'Bank-Grade Encrypted Payments',
    desc: 'Rent and security deposits remain protected inside end-to-end encrypted gateways.',
    tag: 'Financial Safety',
  },
  {
    icon: HiDocumentText,
    title: 'Legally Binding Digital Leases',
    desc: 'Generated instantly in accordance with Moroccan rental standards and signed securely online within minutes.',
    wide: true,
    tag: 'Instant Contracts',
  },
];

const TESTIMONIALS = [
  {
    name: 'Fatima Zahra',
    role: 'Tenant in Casablanca',
    text: 'SAKANIA made finding an apartment so seamless. The verified listings gave me total confidence in my deposit without dealing with unreliable middle agents.',
    rating: 5,
    location: 'Gauthier, Casablanca',
  },
  {
    name: 'Youssef Amrani',
    role: 'Property Owner',
    text: 'Managing rent payments and verified tenants has never been smoother. SAKANIA handles the trust factor effortlessly and saves me hours every week.',
    rating: 5,
    location: 'Hivernage, Marrakech',
  },
  {
    name: 'Amina Benali',
    role: 'Tenant in Marrakech',
    text: 'The digital contract process was unbelievably fast. I signed electronically and moved into my new apartment within 3 days!',
    rating: 5,
    location: 'Agdal, Rabat',
  },
];

const FAQS = [
  {
    q: 'How do I list my property on SAKANIA?',
    a: 'Register as an owner, then upload your property details, high-res photos, and ownership documents. Our team conducts verification within 24 hours before your listing goes live.',
  },
  {
    q: 'What are the fees for tenants?',
    a: 'SAKANIA is 100% free for tenants to search, filter, explore property details, and submit reservation requests.',
  },
  {
    q: 'How does the AI matching assistant work?',
    a: 'It analyzes your preferred budget, proximity to key locations, desired number of rooms, and special amenities to automatically serve high-match property recommendations.',
  },
  {
    q: 'Are online rent payments secure?',
    a: 'Yes. All payments, deposits, and monthly disbursements use bank-grade SSL encryption and secure banking protocols to ensure full peace of mind.',
  },
  {
    q: 'Can I schedule in-person or virtual property tours?',
    a: 'Absolutely. You can request a live guided virtual tour or schedule a convenient physical visit straight from any property page.',
  },
];

/* ---------- Helper Components ---------- */

const Reveal = ({ children, delay = 0, className = '' }) => {
  const reduce = useReducedMotion();
  return (
    <motion.div
      initial={reduce ? false : { opacity: 0, y: 20 }}
      whileInView={{ opacity: 1, y: 0 }}
      viewport={{ once: true, margin: '-40px' }}
      transition={{ duration: 0.6, delay, ease: EASE }}
      className={className}
    >
      {children}
    </motion.div>
  );
};

const SectionHeading = ({ tag, title, subtitle, action, className = '' }) => (
  <Reveal className={`flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between ${className}`}>
    <div className="max-w-2xl">
      {tag && (
        <span className="mb-3 inline-flex items-center gap-1.5 rounded-full border border-orange-200 bg-orange-50/80 px-3 py-1 text-xs font-semibold tracking-wide uppercase text-orange-700 backdrop-blur-sm">
          <HiSparkles className="h-3.5 w-3.5 text-orange-600" />
          {tag}
        </span>
      )}
      <h2 className="font-display text-3xl font-bold tracking-tight text-stone-900 md:text-4xl lg:text-5xl">
        {title}
      </h2>
      {subtitle && <p className="mt-4 text-base leading-relaxed text-stone-600 md:text-lg">{subtitle}</p>}
    </div>
    {action && <div className="mt-4 sm:mt-0">{action}</div>}
  </Reveal>
);

const useCountUp = (end, duration = 1600) => {
  const [count, setCount] = useState(0);
  const ref = useRef(null);
  const started = useRef(false);
  const reduce = useReducedMotion();

  useEffect(() => {
    if (reduce) {
      setCount(end);
      return undefined;
    }
    const el = ref.current;
    if (!el) return undefined;
    let raf;
    const observer = new IntersectionObserver(
      ([entry]) => {
        if (!entry.isIntersecting || started.current) return;
        started.current = true;
        const t0 = performance.now();
        const tick = (now) => {
          const p = Math.min((now - t0) / duration, 1);
          setCount(Math.round((1 - Math.pow(1 - p, 3)) * end));
          if (p < 1) raf = requestAnimationFrame(tick);
        };
        raf = requestAnimationFrame(tick);
      },
      { threshold: 0.4 }
    );
    observer.observe(el);
    return () => {
      observer.disconnect();
      cancelAnimationFrame(raf);
    };
  }, [end, duration, reduce]);

  return { count, ref };
};

const StatItem = ({ value, suffix, label, desc }) => {
  const { count, ref } = useCountUp(value);
  return (
    <div ref={ref} className="group relative p-8 transition-colors hover:bg-stone-50/80">
      <div className="flex items-baseline gap-1 font-display text-4xl font-extrabold tracking-tight text-stone-900 lg:text-5xl">
        <span>{count.toLocaleString()}</span>
        <span className="text-orange-600">{suffix}</span>
      </div>
      <p className="mt-2 text-base font-semibold text-stone-900">{label}</p>
      <p className="mt-1 text-xs text-stone-500">{desc}</p>
    </div>
  );
};

const HeroVisual = ({ properties }) => {
  const main = properties[0];
  const side = properties[1];
  const mainImg = main?.images?.[0];
  const sideImg = side?.images?.[0];

  return (
    <div className="relative mx-auto w-full max-w-lg lg:max-w-none">
      {/* Main Glass Housing Card */}
      <div className="relative aspect-[4/5] overflow-hidden rounded-[2.5rem] bg-stone-900/5 p-3 ring-1 ring-stone-900/10 backdrop-blur-xl shadow-2xl shadow-stone-900/15">
        <div className="relative h-full w-full overflow-hidden rounded-[2rem] bg-stone-200">
          {mainImg ? (
            <img src={getImageUrl(mainImg)} alt={main?.title || 'Featured Home'} className="h-full w-full object-cover" />
          ) : (
            <div className="flex h-full w-full items-center justify-center bg-gradient-to-br from-stone-100 to-stone-200">
              <HiHome className="h-20 w-20 text-stone-300" />
            </div>
          )}
          <div className="absolute inset-0 bg-gradient-to-t from-stone-950/80 via-stone-950/20 to-transparent" />

          {/* Top Verified Tag */}
          <div className="absolute left-4 top-4 flex items-center gap-2 rounded-full border border-white/20 bg-stone-900/75 px-3.5 py-1.5 text-xs font-semibold text-white shadow-lg backdrop-blur-md">
            <HiCheckBadge className="h-4 w-4 text-orange-400" />
            Verified Moroccan Property
          </div>

          {/* Floating Details Overlay on Main Image */}
          {main && (
            <div className="absolute bottom-6 left-6 right-6 rounded-2xl border border-white/20 bg-stone-900/80 p-4 text-white shadow-2xl backdrop-blur-md">
              <div className="flex items-start justify-between gap-2">
                <div>
                  <p className="line-clamp-1 text-base font-bold">{main.title}</p>
                  <p className="mt-1 flex items-center gap-1.5 text-xs text-stone-300">
                    <HiMapPin className="h-3.5 w-3.5 text-orange-400 shrink-0" />
                    {main.location} {main.neighborhood ? `• ${main.neighborhood}` : ''}
                  </p>
                </div>
                <span className="rounded-lg bg-orange-600/90 px-2.5 py-1 text-xs font-bold text-white backdrop-blur">
                  Featured
                </span>
              </div>
              <div className="mt-3 flex items-center justify-between border-t border-white/10 pt-3">
                <p className="text-lg font-extrabold text-orange-400">
                  {main.price_per_month?.toLocaleString()}{' '}
                  <span className="text-xs font-normal text-stone-300">MAD / mo</span>
                </p>
                <Link
                  to={`/properties/${main.id}`}
                  className="inline-flex items-center gap-1 text-xs font-semibold text-white hover:text-orange-300 transition-colors"
                >
                  View Details <HiArrowUpRight className="h-3.5 w-3.5" />
                </Link>
              </div>
            </div>
          )}
        </div>
      </div>

      {/* Floating Side Secondary Card */}
      {sideImg && (
        <motion.div
          initial={{ y: 20, opacity: 0 }}
          animate={{ y: 0, opacity: 1 }}
          transition={{ delay: 0.5, duration: 0.8 }}
          className="absolute -bottom-6 -right-4 hidden w-52 overflow-hidden rounded-2xl border border-stone-200 bg-white p-2.5 shadow-2xl shadow-stone-900/20 sm:block lg:-right-8"
        >
          <div className="aspect-video overflow-hidden rounded-xl bg-stone-100">
            <img src={getImageUrl(sideImg)} alt={side?.title || 'Secondary Property'} className="h-full w-full object-cover" />
          </div>
          <div className="mt-2 px-1">
            <p className="line-clamp-1 text-xs font-bold text-stone-900">{side.title}</p>
            <p className="text-[11px] font-semibold text-orange-600">
              {side.price_per_month?.toLocaleString()} MAD <span className="font-normal text-stone-400">/ mo</span>
            </p>
          </div>
        </motion.div>
      )}

      {/* Floating SaaS AI Badge */}
      <motion.div
        initial={{ scale: 0.9, opacity: 0 }}
        animate={{ scale: 1, opacity: 1 }}
        transition={{ delay: 0.7, duration: 0.5 }}
        className="absolute -left-6 top-12 hidden items-center gap-3 rounded-2xl border border-stone-200/80 bg-white/95 p-3.5 shadow-xl backdrop-blur sm:flex"
      >
        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-orange-500 to-amber-600 text-white shadow-md shadow-orange-500/20">
          <HiSparkles className="h-5 w-5" />
        </div>
        <div>
          <p className="text-xs font-bold text-stone-900">99.4% AI Match</p>
          <p className="text-[11px] text-stone-500">Based on user preferences</p>
        </div>
      </motion.div>
    </div>
  );
};

/* ---------- Main Component ---------- */

const Home = () => {
  const navigate = useNavigate();
  const reduce = useReducedMotion();
  const [properties, setProperties] = useState(() => {
    const warm = peekFeaturedProperties();
    return warm?.data?.data || warm?.data || [];
  });
  const [search, setSearch] = useState('');
  const [openFaq, setOpenFaq] = useState(0);

  useEffect(() => {
    getFeaturedProperties()
      .then((res) => setProperties(res.data?.data || res.data || []))
      .catch(() => { });
  }, []);

  const handleSearch = (e) => {
    e.preventDefault();
    navigate(`/properties?search=${encodeURIComponent(search)}`);
  };

  const enter = (delay) =>
    reduce
      ? {}
      : {
        initial: { opacity: 0, y: 16 },
        animate: { opacity: 1, y: 0 },
        transition: { duration: 0.6, delay, ease: EASE },
      };

  const [lead, ...otherTestimonials] = TESTIMONIALS;

  return (
    <div className="min-h-screen bg-stone-50/50 text-stone-900 antialiased selection:bg-stone-900 selection:text-white">
      <PublicNavbar />
      {/* HERO SECTION */}
      <section className="relative overflow-hidden bg-white border-b border-stone-200/60 pb-20 pt-10 md:pb-32 md:pt-16">
        <div className="pointer-events-none absolute right-0 top-1/4 h-96 w-96 rounded-full bg-orange-400/10 blur-3xl" />

        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
          <div className="grid items-center gap-12 lg:grid-cols-12 lg:gap-8">
            {/* Left Hero Content */}
            <div className="lg:col-span-7">
              <motion.div {...enter(0.05)} className="inline-flex items-center gap-2 rounded-full border border-orange-200/80 bg-white px-3.5 py-1.5 shadow-sm shadow-orange-500/5">
                <span className="flex h-5 w-5 items-center justify-center rounded-full bg-orange-600 text-white">
                  <HiCheckBadge className="h-3.5 w-3.5" />
                </span>
                <span className="text-xs font-bold tracking-wide uppercase text-stone-800">
                  Morocco&rsquo;s #1 Verified Rental Platform
                </span>
              </motion.div>

              <motion.h1
                className="mt-6 font-display text-4xl font-extrabold tracking-tight text-stone-900 sm:text-5xl md:text-6xl lg:leading-[1.08]"
              >
                Rent verified homes with <span className="bg-gradient-to-r from-orange-600 via-amber-600 to-orange-500 bg-clip-text text-transparent">total confidence</span> across Morocco.
              </motion.h1>

              <motion.p {...enter(0.2)} className="mt-6 max-w-xl text-lg leading-relaxed text-stone-600">
                Discover authentic properties, automate your digital contracts, and settle rent safely with zero hidden agent markups.
              </motion.p>

              {/* Search Bar Widget */}
              <motion.form onSubmit={handleSearch} role="search" {...enter(0.28)} className="mt-8 max-w-2xl">
                <div className="relative rounded-2xl border border-stone-200 bg-white/90 p-2 shadow-2xl shadow-stone-900/10 backdrop-blur-md transition-all focus-within:border-orange-500 focus-within:ring-4 focus-within:ring-orange-500/10 sm:flex sm:items-center">
                  <div className="flex flex-1 items-center gap-3 px-3 py-2 sm:py-0">
                    <HiMapPin className="h-6 w-6 text-orange-600 shrink-0" />
                    <Input
                      id="home-search"
                      type="text"
                      value={search}
                      onChange={(e) => setSearch(e.target.value)}
                      placeholder="City, neighborhood, or street address..."
                      className="h-12 border-0 bg-transparent px-0 text-base shadow-none placeholder:text-stone-400 focus-visible:ring-0"
                    />
                  </div>
                  <Button
                    type="submit"
                    size="lg"
                    className="h-12 w-full rounded-xl bg-orange-600 px-7 font-bold text-white shadow-lg shadow-orange-600/25 transition-all hover:bg-orange-500 hover:shadow-orange-600/40 sm:w-auto"
                  >
                    <HiMagnifyingGlass className="mr-2 h-5 w-5" />
                    Search Listings
                  </Button>
                </div>
              </motion.form>

              {/* Popular Cities Pills */}
              <motion.div {...enter(0.36)} className="mt-6 flex flex-wrap items-center gap-2 text-sm">
                <span className="font-medium text-stone-400">Top locations:</span>
                {CITIES.map((city) => (
                  <Link
                    key={city}
                    to={`/properties?city=${city}`}
                    className="rounded-full border border-stone-200 bg-white/80 px-3.5 py-1 text-xs font-semibold text-stone-700 transition-all hover:border-orange-300 hover:bg-orange-50 hover:text-orange-700"
                  >
                    {city}
                  </Link>
                ))}
              </motion.div>

              {/* Value Micro-Points */}
              <motion.ul {...enter(0.44)} className="mt-8 flex flex-wrap gap-x-8 gap-y-3 text-xs font-bold uppercase tracking-wider text-stone-500">
                {['Zero Commission Surprises', 'Verified Identity Owners', 'Instant Digital Lease'].map((item) => (
                  <li key={item} className="flex items-center gap-2">
                    <HiCheck className="h-4 w-4 text-orange-600" />
                    {item}
                  </li>
                ))}
              </motion.ul>
            </div>

            {/* Right Hero Visual Showcase */}
            <motion.div {...enter(0.2)} className="lg:col-span-5">
              <HeroVisual properties={properties} />
            </motion.div>
          </div>
        </div>
      </section>

      {/* STATS STRIP */}
      <section aria-label="SAKANIA in numbers" className="border-y border-stone-200/80 bg-white">
        <div className="mx-auto max-w-7xl">
          <div className="grid grid-cols-2 divide-x divide-y divide-stone-200/80 md:grid-cols-4 md:divide-y-0">
            {STATS.map((s) => (
              <StatItem key={s.label} {...s} />
            ))}
          </div>
        </div>
      </section>

      {/* FEATURED PROPERTIES SECTION */}
      <section className="py-20 md:py-28 bg-stone-50/60">
        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
          <SectionHeading
            tag="Curated Portfolio"
            title="Explore Featured Properties"
            subtitle="Vetted luxury apartments, modern villas, and cozy studios ready for move-in."
            action={
              <Link
                to="/properties"
                className="inline-flex items-center gap-2 rounded-xl border border-stone-200 bg-white px-5 py-2.5 text-sm font-semibold text-stone-800 shadow-sm transition-all duration-300 hover:border-orange-500 hover:text-orange-600 hover:shadow-md"
              >
                Browse All Properties
                <HiArrowRight className="h-4 w-4 transition-transform duration-300 group-hover:translate-x-1" />
              </Link>
            }
          />

          {/* Grid Cards */}
          <div className="mt-12 grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
            {properties.length > 0
              ? properties.slice(0, 6).map((property, i) => (
                <motion.div
                  key={property.id}
                  initial={{ opacity: 0, y: 16 }}
                  animate={{ opacity: 1, y: 0 }}
                  transition={{ delay: i * 0.05, duration: 0.35, ease: [0.16, 1, 0.3, 1] }}
                  className="h-full"
                >
                  <div className="group relative flex h-full flex-col overflow-hidden rounded-2xl border border-stone-200/80 bg-white shadow-sm transition-all duration-300 hover:-translate-y-1 hover:border-stone-300 hover:shadow-xl hover:shadow-stone-900/5">
                    <Link to={`/properties/${property.id}`} className="flex flex-1 flex-col">
                      {/* Image Frame */}
                      <div className="relative aspect-[4/3] w-full overflow-hidden bg-stone-100">
                        {property.images?.[0] ? (
                          <img
                            src={getImageUrl(property.images[0])}
                            alt={property.title}
                            loading="lazy"
                            className="h-full w-full object-cover transition-transform duration-500 ease-out group-hover:scale-105"
                          />
                        ) : (
                          <div className="flex h-full w-full items-center justify-center bg-stone-100">
                            <HiHome className="h-10 w-10 text-stone-300" />
                          </div>
                        )}

                        {/* Badges Overlay */}
                        <div className="absolute inset-x-3 top-3 flex items-center justify-between pointer-events-none">
                          {property.type ? (
                            <span className="rounded-md bg-stone-900/75 px-2.5 py-1 text-[11px] font-semibold capitalize tracking-wide text-white backdrop-blur-md shadow-sm">
                              {property.type}
                            </span>
                          ) : <span />}

                          {property.status === 'available' && (
                            <span className="inline-flex items-center gap-1.5 rounded-md bg-emerald-500/90 px-2.5 py-1 text-[11px] font-semibold text-white shadow-sm backdrop-blur-md">
                              <span className="h-1.5 w-1.5 rounded-full bg-white animate-pulse" />
                              Available
                            </span>
                          )}
                        </div>
                      </div>

                      {/* Content */}
                      <div className="flex flex-1 flex-col justify-between p-5">
                        <div>
                          <h3 className="line-clamp-1 text-base font-bold text-stone-900 transition-colors duration-200 group-hover:text-orange-600">
                            {property.title}
                          </h3>
                          <p className="mt-1.5 flex items-center gap-1 text-xs font-medium text-stone-500">
                            <HiMapPin className="h-3.5 w-3.5 text-orange-500 shrink-0" />
                            <span className="truncate">
                              {property.location || property.city}
                            </span>
                          </p>
                        </div>

                        {/* Specs & Price */}
                        <div className="mt-5 border-t border-stone-100 pt-3.5">
                          <div className="flex items-center justify-between">
                            <div>
                              <span className="text-lg font-extrabold text-stone-900">
                                {property.price_per_month?.toLocaleString()}
                              </span>
                              <span className="ml-1 text-xs font-medium text-stone-400">MAD/mo</span>
                            </div>

                            <div className="flex items-center gap-2.5 text-xs font-medium text-stone-500">
                              <span>{property.rooms} beds</span>
                              <span>•</span>
                              <span>{property.bathrooms} baths</span>
                              {property.area && (
                                <>
                                  <span>•</span>
                                  <span>{property.area} m²</span>
                                </>
                              )}
                            </div>
                          </div>

                          {/* View Details Button */}
                          <div className="mt-4 flex items-center justify-center gap-1.5 rounded-xl bg-stone-100 px-4 py-2.5 text-xs font-bold text-stone-700 transition-all duration-300 group-hover:bg-orange-600 group-hover:text-white">
                            <span>View Details</span>
                            <HiArrowUpRight className="h-3.5 w-3.5 transition-transform duration-300 group-hover:translate-x-0.5 group-hover:-translate-y-0.5" />
                          </div>
                        </div>
                      </div>
                    </Link>
                  </div>
                </motion.div>
              ))
              : [...Array(6)].map((_, i) => (
                <div key={i} className="overflow-hidden rounded-2xl border border-stone-200 bg-white p-3.5">
                  <div className="aspect-[4/3] animate-pulse rounded-xl bg-stone-200" />
                  <div className="mt-3.5 h-4 w-3/4 animate-pulse rounded bg-stone-200" />
                  <div className="mt-2 h-3 w-1/2 animate-pulse rounded bg-stone-200" />
                </div>
              ))}
          </div>
        </div>
      </section>

      {/* HOW IT WORKS */}
      <section className="py-24 md:py-32 border-y border-stone-200/80 bg-white relative overflow-hidden">
        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
          <SectionHeading
            tag="Simple Workflow"
            title="How SAKANIA Works"
            subtitle="From searching to key handover in three frictionless steps."
          />

          <div className="relative mt-16 grid gap-8 md:grid-cols-3">
            {STEPS.map((step, i) => (
              <Reveal key={step.title} delay={i * 0.1}>
                <div className="group relative h-full rounded-3xl border border-stone-200/80 bg-stone-50/50 p-8 transition-all hover:border-orange-300 hover:bg-white hover:shadow-xl hover:shadow-orange-500/5">
                  <div className="flex items-center justify-between">
                    <span className="flex h-14 w-14 items-center justify-center rounded-2xl bg-stone-900 text-white shadow-lg transition-transform group-hover:scale-110 group-hover:bg-orange-600">
                      <step.icon className="h-6 w-6" />
                    </span>
                    <span className="text-xs font-extrabold uppercase tracking-widest text-orange-600">
                      {step.badge}
                    </span>
                  </div>

                  <h3 className="mt-8 text-xl font-bold text-stone-900">{step.title}</h3>
                  <p className="mt-3 text-sm leading-relaxed text-stone-600">{step.desc}</p>
                </div>
              </Reveal>
            ))}
          </div>
        </div>
      </section>

      {/* WHY SAKANIA (BENTO GRID STYLE) */}
      <section className="py-24 md:py-32 bg-stone-50/50">
        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
          <SectionHeading
            tag="Built For Speed & Trust"
            title="Designed For Modern Renters & Owners"
            subtitle="We removed friction, uncertainty, and inflated agent fees from Moroccan real estate."
          />

          <Reveal className="mt-14 grid gap-6 lg:grid-cols-3">
            {FEATURES.map((f) => (
              <div
                key={f.title}
                className={`group relative flex flex-col justify-between overflow-hidden rounded-3xl p-8 lg:p-10 transition-all ${f.wide ? 'lg:col-span-2' : ''
                  } ${f.dark
                    ? 'bg-gradient-to-br from-stone-950 via-stone-900 to-stone-950 text-white shadow-2xl'
                    : 'border border-stone-200/80 bg-white text-stone-900 shadow-sm hover:border-orange-300 hover:shadow-xl'
                  }`}
              >
                {f.dark && (
                  <div className="pointer-events-none absolute -right-20 -top-20 h-64 w-64 rounded-full bg-orange-600/20 blur-3xl" />
                )}

                <div>
                  <div className="flex items-center justify-between">
                    <span
                      className={`flex h-12 w-12 items-center justify-center rounded-2xl shadow-md ${f.dark ? 'bg-orange-600 text-white' : 'bg-orange-100/80 text-orange-700'
                        }`}
                    >
                      <f.icon className="h-6 w-6" />
                    </span>
                    <span
                      className={`text-xs font-extrabold uppercase tracking-wider ${f.dark ? 'text-orange-400' : 'text-stone-400'
                        }`}
                    >
                      {f.tag}
                    </span>
                  </div>

                  <h3 className="mt-8 font-display text-2xl font-bold">{f.title}</h3>
                  <p className={`mt-3 text-base leading-relaxed ${f.dark ? 'text-stone-300' : 'text-stone-600'}`}>
                    {f.desc}
                  </p>
                </div>

                <div className="mt-8 flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-orange-600">
                  <span>Learn details</span>
                  <HiChevronRight className="h-4 w-4" />
                </div>
              </div>
            ))}
          </Reveal>
        </div>
      </section>

      {/* TESTIMONIALS */}
      <section className="border-y border-stone-200/80 bg-white py-24 md:py-32">
        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
          <SectionHeading
            tag="Customer Proof"
            title="Trusted By Hundreds Across Morocco"
            subtitle="Hear how tenants and property owners experienced seamless transactions."
          />

          <Reveal className="mt-14 grid gap-8 lg:grid-cols-5">
            {/* Featured Hero Testimonial */}
            <figure className="relative flex flex-col justify-between rounded-3xl border border-stone-200/80 bg-stone-50/70 p-8 shadow-sm md:p-10 lg:col-span-3">
              <div>
                <div className="flex gap-1 text-amber-500" aria-label={`${lead.rating} out of 5 stars`}>
                  {[...Array(lead.rating)].map((_, i) => (
                    <HiStar key={i} className="h-5 w-5 fill-amber-400" />
                  ))}
                </div>
                <blockquote className="mt-6 font-display text-2xl font-bold leading-snug text-stone-900 md:text-3xl">
                  &ldquo;{lead.text}&rdquo;
                </blockquote>
              </div>
              <figcaption className="mt-10 flex items-center justify-between border-t border-stone-200/60 pt-6">
                <div className="flex items-center gap-4">
                  <span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-stone-900 font-bold text-white shadow-md">
                    {lead.name.charAt(0)}
                  </span>
                  <div>
                    <span className="block font-bold text-stone-900">{lead.name}</span>
                    <span className="block text-xs font-medium text-stone-500">{lead.role}</span>
                  </div>
                </div>
                <span className="hidden text-xs font-semibold text-stone-400 sm:block">{lead.location}</span>
              </figcaption>
            </figure>

            {/* Secondary Testimonials Stack */}
            <div className="flex flex-col gap-6 lg:col-span-2">
              {otherTestimonials.map((t) => (
                <figure
                  key={t.name}
                  className="flex flex-1 flex-col justify-between rounded-3xl border border-stone-200/80 bg-white p-7 shadow-sm transition-all hover:border-orange-300 hover:shadow-lg"
                >
                  <blockquote className="text-sm leading-relaxed text-stone-700">&ldquo;{t.text}&rdquo;</blockquote>
                  <figcaption className="mt-6 flex items-center gap-3 border-t border-stone-100 pt-4">
                    <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-orange-100 font-bold text-orange-800 text-xs">
                      {t.name.charAt(0)}
                    </span>
                    <div>
                      <span className="block text-xs font-bold text-stone-900">{t.name}</span>
                      <span className="block text-[11px] text-stone-500">{t.role}</span>
                    </div>
                  </figcaption>
                </figure>
              ))}
            </div>
          </Reveal>
        </div>
      </section>

      {/* FAQ SECTION */}
      <section className="py-24 md:py-32 bg-stone-50/50">
        <div className="mx-auto grid max-w-7xl gap-12 px-4 sm:px-6 lg:grid-cols-12 lg:px-8">
          <Reveal className="lg:col-span-4">
            <div className="lg:sticky lg:top-28">
              <span className="mb-3 inline-flex items-center gap-1.5 rounded-full border border-orange-200 bg-orange-50 px-3 py-1 text-xs font-semibold uppercase text-orange-700">
                Support & Info
              </span>
              <h2 className="font-display text-3xl font-bold tracking-tight text-stone-900 md:text-4xl">
                Frequently Asked Questions
              </h2>
              <p className="mt-4 text-base leading-relaxed text-stone-600">
                Have questions regarding the verification process, leases, or online deposits? We&rsquo;re here to answer.
              </p>
            </div>
          </Reveal>

          <Reveal className="lg:col-span-8">
            <div className="space-y-4">
              {FAQS.map((faq, i) => {
                const open = openFaq === i;
                return (
                  <div
                    key={faq.q}
                    className={`overflow-hidden rounded-2xl border transition-all ${open ? 'border-orange-300 bg-white shadow-md' : 'border-stone-200/80 bg-white hover:border-stone-300'
                      }`}
                  >
                    <h3>
                      <button
                        type="button"
                        aria-expanded={open}
                        aria-controls={`faq-panel-${i}`}
                        id={`faq-button-${i}`}
                        onClick={() => setOpenFaq(open ? null : i)}
                        className="flex w-full items-center justify-between gap-6 p-6 text-left"
                      >
                        <span className="text-base font-bold text-stone-900">{faq.q}</span>
                        <span
                          className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-xl transition-colors ${open ? 'bg-orange-600 text-white' : 'bg-stone-100 text-stone-600'
                            }`}
                        >
                          {open ? <HiMinus className="h-4 w-4" /> : <HiPlus className="h-4 w-4" />}
                        </span>
                      </button>
                    </h3>
                    <AnimatePresence initial={false}>
                      {open && (
                        <motion.div
                          id={`faq-panel-${i}`}
                          role="region"
                          aria-labelledby={`faq-button-${i}`}
                          initial={{ height: 0, opacity: 0 }}
                          animate={{ height: 'auto', opacity: 1 }}
                          exit={{ height: 0, opacity: 0 }}
                          transition={{ duration: reduce ? 0 : 0.25, ease: EASE }}
                        >
                          <p className="px-6 pb-6 text-sm leading-relaxed text-stone-600">{faq.a}</p>
                        </motion.div>
                      )}
                    </AnimatePresence>
                  </div>
                );
              })}
            </div>
          </Reveal>
        </div>
      </section>

      {/* OWNER CTA HERO BANNER */}
      <section className="pb-24 md:pb-32">
        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
          <Reveal>
            <div className="relative overflow-hidden rounded-[2.5rem] bg-gradient-to-br from-stone-950 via-stone-900 to-stone-950 p-10 text-white shadow-2xl md:p-16">
              {/* Background ambient lighting */}
              <div className="pointer-events-none absolute -right-24 -top-24 h-96 w-96 rounded-full bg-orange-600/30 blur-3xl" />
              <div className="pointer-events-none absolute -left-24 -bottom-24 h-96 w-96 rounded-full bg-amber-600/20 blur-3xl" />

              <div className="relative grid items-center gap-10 lg:grid-cols-12">
                <div className="lg:col-span-8">
                  <span className="inline-flex items-center gap-2 rounded-full border border-orange-500/30 bg-orange-500/10 px-3.5 py-1 text-xs font-bold uppercase tracking-wider text-orange-400 backdrop-blur-md">
                    <HiBuildingOffice2 className="h-4 w-4" />
                    For Property Owners
                  </span>
                  <h2 className="mt-6 font-display text-3xl font-extrabold tracking-tight sm:text-4xl md:text-5xl">
                    Own a property? List it for free on SAKANIA.
                  </h2>
                  <p className="mt-4 max-w-2xl text-base text-stone-300 md:text-lg">
                    Automate tenant background checks, issue digital leases, and collect rent securely directly into your Moroccan bank account.
                  </p>
                </div>

                <div className="flex flex-col gap-4 sm:flex-row lg:col-span-4 lg:flex-col lg:items-stretch">
                  <Link
                    to="/register"
                    className="inline-flex h-13 items-center justify-center gap-2 rounded-xl bg-orange-600 px-8 font-bold text-white shadow-xl shadow-orange-600/30 transition-all hover:bg-orange-500 hover:shadow-orange-600/50"
                  >
                    List Your Property
                    <HiArrowRight className="h-4 w-4" />
                  </Link>
                  <Link
                    to="/properties"
                    className="inline-flex h-13 items-center justify-center rounded-xl border border-white/20 bg-white/5 px-8 font-bold text-white backdrop-blur-md transition-all hover:bg-white/10"
                  >
                    Browse Existing Listings
                  </Link>
                </div>
              </div>
            </div>
          </Reveal>
        </div>
      </section>

      <Footer />
    </div>
  );
};

export default Home;