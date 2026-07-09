import { useState, useEffect, useRef } from 'react';
import { Link } from 'react-router-dom';
import {
  Menu,
  X,
  Clock,
  CheckCircle,
  Users,
  BarChart3,
  Calendar,
  Shield,
  Github,
  ExternalLink,
  Star,
  ArrowRight,
  Zap,
  Globe,
  TrendingUp,
  Lock,
  Heart
} from 'lucide-react';

const features = [
  {
    icon: Clock,
    title: 'Check-in/Check-out Otomatis',
    description: 'Sistem pencatatan kehadiran dengan GPS tracking dan auto-time detection yang akurat.'
  },
  {
    icon: CheckCircle,
    title: 'Task Management',
    description: 'Kelola tugas harian dengan status tracking dan progress monitoring real-time.'
  },
  {
    icon: Users,
    title: 'Team Management',
    description: 'Kelola tim, atur hak akses, dan monitor performa karyawan secara efisien.'
  },
  {
    icon: BarChart3,
    title: 'Analytics Dashboard',
    description: 'Laporan komprehensif dengan grafik interaktif untuk insight bisnis.'
  },
  {
    icon: Calendar,
    title: 'Leave & Holiday Management',
    description: 'Sistem cuti dan libur terintegrasi dengan approval workflow yang fleksibel.'
  },
  {
    icon: Shield,
    title: 'Security & Privacy',
    description: 'Data terenkripsi, multi-tenant architecture, dan akses kontrol berbasis peran.'
  }
];

// Colorful tiles give the neo-brutalist grid personality without breaking the
// "green = action only" rule — these are decorative icon chips, not CTAs.
const tileAccents = ['bg-primary', 'bg-accent', 'bg-facebook', 'bg-brand', 'bg-instagram', 'bg-ink'];

const testimonials = [
  {
    name: 'Wazir Qorni Abud',
    role: 'CEO, Santri Link',
    content: 'WFH Attendance System sangat membantu tim kami yang bekerja remote. Dashboardnya lengkap dan mudah digunakan.',
    rating: 5
  },
  {
    name: 'Adam Ahmad',
    role: 'HR Manager, Digital Agency Gresik',
    content: 'Sistem yang powerful namun tetap user-friendly. Fitur laporan kehadirannya sangat detail dan membantu proses payroll.',
    rating: 5
  },
  {
    name: 'Gabriel Dimas Wicaksono',
    role: 'CTO, Creative Studio Malang',
    content: 'Open source solution yang sangat value for money. Kami bisa custom sesuai kebutuhan tanpa biaya license.',
    rating: 5
  }
];

const stats = [
  { label: 'Perusahaan', value: '500+', icon: TrendingUp, tile: 'bg-primary' },
  { label: 'Pengguna Aktif', value: '10K+', icon: Users, tile: 'bg-accent' },
  { label: 'Check-in/hari', value: '50K+', icon: Clock, tile: 'bg-facebook' },
  { label: 'Kepuasan', value: '98%', icon: Heart, tile: 'bg-instagram' }
];

// Intersection Observer for animations
const useIntersectionObserver = (ref, options = {}) => {
  const [isIntersecting, setIsIntersecting] = useState(false);

  useEffect(() => {
    const observer = new IntersectionObserver(([entry]) => {
      setIsIntersecting(entry.isIntersecting);
    }, options);

    if (ref.current) {
      observer.observe(ref.current);
    }

    return () => {
      if (ref.current) {
        observer.unobserve(ref.current);
      }
    };
  }, [ref, options]);

  return isIntersecting;
};

// Monogram initials for testimonial bylines (avatars are typographic, not photographic)
const getInitials = (name) =>
  name
    .split(' ')
    .map((part) => part[0])
    .slice(0, 2)
    .join('')
    .toUpperCase();

// Typographic mockup of the employee dashboard — no screenshot asset, just
// the same design tokens as the real app, so it never goes stale.
const HeroMockup = ({ inView }) => (
  <div
    className={`relative w-full max-w-md transition-all duration-700 ${
      inView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-6'
    }`}
  >
    <div className="relative hero-float">
      <div className="rounded-card border-2 border-ink bg-white shadow-brutal-xl overflow-hidden">
        {/* Window chrome */}
        <div className="flex items-center gap-2 px-4 py-3 border-b-2 border-ink bg-surface-soft">
          <span className="w-2.5 h-2.5 rounded-full border-2 border-ink bg-error" />
          <span className="w-2.5 h-2.5 rounded-full border-2 border-ink bg-warning" />
          <span className="w-2.5 h-2.5 rounded-full border-2 border-ink bg-primary" />
          <span className="ml-3 font-display text-caption uppercase text-muted-soft truncate">
            wfh.web.id
          </span>
        </div>

        <div className="p-5 sm:p-6 space-y-5">
          <div className="flex items-center justify-between gap-4">
            <div>
              <p className="caption-uppercase mb-1">Status Saat Ini</p>
              <div className="flex items-center gap-2">
                <span className="relative flex h-2 w-2">
                  <span className="hero-pulse absolute inline-flex h-full w-full rounded-full bg-primary opacity-75" />
                  <span className="relative inline-flex h-2 w-2 rounded-full bg-primary" />
                </span>
                <span className="font-display font-bold text-title-md text-primary-active">Sudah Check In</span>
              </div>
            </div>
            <div className="w-10 h-10 shrink-0 rounded-xl border-2 border-ink bg-primary flex items-center justify-center shadow-brutal-sm">
              <Clock className="w-4 h-4 text-white" />
            </div>
          </div>

          <div className="space-y-2">
            <div className="flex justify-between caption-uppercase">
              <span>05:30 Bekerja</span>
              <span>07:00 Wajib</span>
            </div>
            <div className="w-full h-2.5 rounded-full border-2 border-ink bg-surface-elevated overflow-hidden">
              <div className="hero-progress h-full bg-primary" />
            </div>
          </div>

          <div className="space-y-2 border-t-2 border-ink pt-4">
            <p className="caption-uppercase">Tugas Hari Ini</p>
            <ul className="space-y-2">
              <li className="flex items-center gap-2 font-sans text-sm text-body">
                <CheckCircle className="w-3.5 h-3.5 text-primary shrink-0" />
                <span>Review laporan mingguan</span>
              </li>
              <li className="flex items-center gap-2 font-sans text-sm text-body">
                <CheckCircle className="w-3.5 h-3.5 text-primary shrink-0" />
                <span>Update dokumentasi API</span>
              </li>
              <li className="flex items-center gap-2 font-sans text-sm text-muted">
                <span className="w-3.5 h-3.5 rounded-full border-2 border-ink shrink-0" />
                <span>Sinkron dengan tim desain</span>
              </li>
            </ul>
          </div>

          <div className="grid grid-cols-2 gap-3 border-t-2 border-ink pt-4">
            <div>
              <p className="caption-uppercase mb-1">Jam Kerja</p>
              <p className="font-display font-bold text-title-md text-ink">05:30</p>
            </div>
            <div>
              <p className="caption-uppercase mb-1">Jam Tersisa</p>
              <p className="font-display font-bold text-title-md text-warning">01:30</p>
            </div>
          </div>
        </div>
      </div>

      {/* Floating accent badges */}
      <div className="hero-float-alt absolute -top-5 -right-3 sm:-right-6 hidden sm:flex items-center gap-2 rounded-full border-2 border-ink bg-white px-4 py-2 shadow-brutal">
        <CheckCircle className="w-4 h-4 text-primary" />
        <span className="font-display text-caption uppercase text-ink">Check-in Tersimpan</span>
      </div>

      <div
        className="hero-float-alt absolute -bottom-5 -left-3 sm:-left-6 hidden sm:flex items-center gap-2 rounded-full border-2 border-ink bg-white px-4 py-2 shadow-brutal"
        style={{ animationDelay: '1.5s' }}
      >
        <Users className="w-4 h-4 text-accent" />
        <span className="font-display text-caption uppercase text-ink">12 Tim Online</span>
      </div>
    </div>
  </div>
);

export const LandingPage = () => {
  const [isMenuOpen, setIsMenuOpen] = useState(false);
  const [scrolled, setScrolled] = useState(false);
  const [activeTestimonial, setActiveTestimonial] = useState(0);
  const [isLoaded, setIsLoaded] = useState(false);

  // Refs for intersection observer
  const heroRef = useRef();
  const featuresRef = useRef();
  const statsRef = useRef();
  const testimonialsRef = useRef();
  const ctaRef = useRef();

  // Intersection observers
  const heroInView = useIntersectionObserver(heroRef, { threshold: 0.1 });
  const featuresInView = useIntersectionObserver(featuresRef, { threshold: 0.1 });
  const statsInView = useIntersectionObserver(statsRef, { threshold: 0.1 });
  const testimonialsInView = useIntersectionObserver(testimonialsRef, { threshold: 0.1 });
  const ctaInView = useIntersectionObserver(ctaRef, { threshold: 0.1 });

  useEffect(() => {
    setIsLoaded(true);

    const handleScroll = () => {
      setScrolled(window.scrollY > 20);
    };

    const interval = setInterval(() => {
      setActiveTestimonial((prev) => (prev + 1) % testimonials.length);
    }, 5000);

    window.addEventListener('scroll', handleScroll);
    return () => {
      window.removeEventListener('scroll', handleScroll);
      clearInterval(interval);
    };
  }, []);

  // Close mobile menu when clicking outside
  useEffect(() => {
    const handleClickOutside = (event) => {
      if (isMenuOpen && !event.target.closest('nav')) {
        setIsMenuOpen(false);
      }
    };

    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, [isMenuOpen]);

  // Close mobile menu on scroll
  useEffect(() => {
    const handleScroll = () => {
      if (isMenuOpen) {
        setIsMenuOpen(false);
      }
    };

    if (isMenuOpen) {
      window.addEventListener('scroll', handleScroll);
      return () => window.removeEventListener('scroll', handleScroll);
    }
  }, [isMenuOpen]);

  const handleMenuClose = () => {
    setIsMenuOpen(false);
  };

  const handleNavClick = (section) => {
    setIsMenuOpen(false);
    const element = document.getElementById(section);
    if (element) {
      element.scrollIntoView({ behavior: 'smooth' });
    }
  };

  const renderStars = (rating) => {
    return Array.from({ length: 5 }, (_, i) => (
      <Star
        key={i}
        className={`w-4 h-4 ${i < rating ? 'fill-warning text-warning' : 'text-muted-soft'}`}
      />
    ));
  };

  return (
    <div className="min-h-screen bg-canvas">
      {/* Navigation */}
      <nav className={`fixed top-0 w-full z-50 transition-colors duration-200 ${
        scrolled ? 'bg-white/95 backdrop-blur border-b-2 border-ink' : 'bg-transparent'
      }`}>
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          <div className="flex justify-between items-center h-16">
            <div className="flex items-center space-x-3">
              <div className="w-9 h-9 rounded-lg border-2 border-ink bg-primary flex items-center justify-center shadow-brutal-sm">
                <Clock className="w-4 h-4 text-white" />
              </div>
              <span className="font-display font-extrabold text-wordmark text-ink">
                WFH Attendance
              </span>
            </div>

            {/* Desktop Menu */}
            <div className="hidden md:flex items-center space-x-8">
              <button
                onClick={() => handleNavClick('features')}
                className="font-display text-nav-link font-semibold text-body hover:text-brand transition-colors"
              >
                Fitur
              </button>
              <button
                onClick={() => handleNavClick('testimonials')}
                className="font-display text-nav-link font-semibold text-body hover:text-brand transition-colors"
              >
                Testimoni
              </button>
              <button
                onClick={() => handleNavClick('opensource')}
                className="font-display text-nav-link font-semibold text-body hover:text-brand transition-colors"
              >
                Open Source
              </button>
              <a
                href="https://github.com/tsdlamongan/WFH-Attendance-System"
                target="_blank"
                rel="noopener noreferrer"
                className="font-display text-nav-link font-semibold text-body hover:text-brand transition-colors flex items-center space-x-1"
              >
                <Github className="w-4 h-4" />
                <span>GitHub</span>
              </a>
            </div>

            <div className="hidden md:flex items-center space-x-4">
              <Link
                to="/login"
                className="font-display text-nav-link font-semibold text-body hover:text-brand transition-colors"
              >
                Masuk
              </Link>
              <Link
                to="/register"
                className="btn-primary btn-compact"
              >
                Daftar Gratis
              </Link>
            </div>

            {/* Mobile Menu Button */}
            <button
              onClick={() => setIsMenuOpen(!isMenuOpen)}
              className="md:hidden p-2 text-body hover:text-ink transition-colors"
              aria-label="Toggle menu"
            >
              {isMenuOpen ? (
                <X className="w-6 h-6" />
              ) : (
                <Menu className="w-6 h-6" />
              )}
            </button>
          </div>

          {/* Mobile Menu */}
          <div className={`md:hidden absolute top-16 left-0 right-0 bg-white border-b-2 border-ink transition-opacity duration-200 ${
            isMenuOpen ? 'opacity-100' : 'opacity-0 pointer-events-none'
          }`}>
            <div className="px-4 py-6 space-y-4">
              <button
                onClick={() => handleNavClick('features')}
                className="block w-full text-left font-display text-nav-link font-semibold text-body hover:text-brand transition-colors"
              >
                Fitur
              </button>
              <button
                onClick={() => handleNavClick('testimonials')}
                className="block w-full text-left font-display text-nav-link font-semibold text-body hover:text-brand transition-colors"
              >
                Testimoni
              </button>
              <button
                onClick={() => handleNavClick('opensource')}
                className="block w-full text-left font-display text-nav-link font-semibold text-body hover:text-brand transition-colors"
              >
                Open Source
              </button>
              <a
                href="https://github.com/tsdlamongan/WFH-Attendance-System"
                target="_blank"
                rel="noopener noreferrer"
                className="flex items-center space-x-2 font-display text-nav-link font-semibold text-body hover:text-brand transition-colors"
              >
                <Github className="w-4 h-4" />
                <span>GitHub</span>
              </a>
              <div className="pt-4 border-t-2 border-ink space-y-3">
                <Link
                  to="/login"
                  onClick={handleMenuClose}
                  className="block text-center font-display text-nav-link font-semibold text-body hover:text-brand transition-colors"
                >
                  Masuk
                </Link>
                <Link
                  to="/register"
                  onClick={handleMenuClose}
                  className="btn-primary w-full"
                >
                  Daftar Gratis
                </Link>
              </div>
            </div>
          </div>
        </div>
      </nav>

      {/* Hero Section */}
      <section ref={heroRef} className="pt-28 pb-24 sm:pt-36 sm:pb-32 lg:pt-44 lg:pb-40 px-4 sm:px-6 lg:px-8 relative">
        <div className="max-w-7xl mx-auto">
          <div className={`grid grid-cols-1 lg:grid-cols-2 gap-16 lg:gap-20 items-center transition-opacity duration-200 ${
            isLoaded && heroInView ? 'opacity-100' : 'opacity-0'
          }`}>
            <div className="text-center lg:text-left space-y-10 sm:space-y-12">
              <div className="space-y-6">
                <div className="inline-flex items-center gap-2 rounded-full border-2 border-ink bg-primary px-4 py-1.5 shadow-brutal-sm font-display text-caption uppercase text-white">
                  <Zap className="w-4 h-4" />
                  <span>100% Gratis &amp; Open Source</span>
                </div>

                <h1 className="font-display font-bold text-display-md sm:text-display-lg lg:text-display-xl text-ink">
                  Sistem Kehadiran
                  <br />
                  <span className="text-accent">Remote Work Terbaik</span>
                </h1>

                <p className="font-sans text-lg sm:text-xl text-body max-w-xl mx-auto lg:mx-0 leading-relaxed">
                  Solusi lengkap untuk manajemen kehadiran tim remote work Anda.
                  <span className="font-semibold text-ink"> 100% Gratis &amp; Open Source</span> -
                  Tanpa biaya berlangganan, tanpa batasan pengguna.
                </p>
              </div>

              <div className="flex flex-col sm:flex-row flex-wrap gap-4 justify-center lg:justify-start items-center">
                <Link
                  to="/register"
                  className="btn-primary w-full sm:w-auto whitespace-nowrap shrink-0"
                >
                  <span>Mulai Gratis Sekarang</span>
                  <ArrowRight className="w-5 h-5" />
                </Link>
                <a
                  href="https://github.com/tsdlamongan/WFH-Attendance-System"
                  target="_blank"
                  rel="noopener noreferrer"
                  className="btn-secondary w-full sm:w-auto whitespace-nowrap shrink-0"
                >
                  <Github className="w-5 h-5" />
                  <span>View on GitHub</span>
                  <ExternalLink className="w-4 h-4" />
                </a>
              </div>

              <div className="grid grid-cols-2 gap-4 sm:flex sm:flex-wrap sm:justify-center lg:justify-start sm:gap-6 max-w-md mx-auto lg:mx-0">
                {[
                  { icon: CheckCircle, text: 'Tanpa Setup' },
                  { icon: Globe, text: 'Mobile Friendly' },
                  { icon: Heart, text: 'Support Indonesia' },
                  { icon: Zap, text: 'Update Berkala' }
                ].map((item, index) => (
                  <div key={index} className="flex items-center gap-2 font-display text-caption uppercase text-muted">
                    <item.icon className="w-4 h-4 text-primary" />
                    <span>{item.text}</span>
                  </div>
                ))}
              </div>
            </div>

            <div className="flex justify-center lg:justify-end">
              <HeroMockup inView={isLoaded && heroInView} />
            </div>
          </div>
        </div>
      </section>

      {/* Stats Section */}
      <section ref={statsRef} className="py-16 sm:py-24 lg:py-section px-4 sm:px-6 lg:px-8 border-t-2 border-ink bg-surface-soft">
        <div className="max-w-7xl mx-auto">
          <div className={`grid grid-cols-2 lg:grid-cols-4 gap-6 sm:gap-8 transition-opacity duration-200 ${
            isLoaded && statsInView ? 'opacity-100' : 'opacity-0'
          }`}>
            {stats.map((stat, index) => (
              <div key={index} className="rounded-card border-2 border-ink bg-white p-6 text-center shadow-brutal">
                <div className="flex justify-center mb-4">
                  <div className={`w-11 h-11 rounded-xl border-2 border-ink ${stat.tile} flex items-center justify-center shadow-brutal-sm`}>
                    <stat.icon className="w-5 h-5 text-white" />
                  </div>
                </div>
                <div className="font-display font-bold text-display-md sm:text-display-lg text-ink">
                  {stat.value}
                </div>
                <div className="caption-uppercase mt-2">{stat.label}</div>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* Features Section */}
      <section ref={featuresRef} id="features" className="py-16 sm:py-24 lg:py-section px-4 sm:px-6 lg:px-8 border-t-2 border-ink">
        <div className="max-w-7xl mx-auto">
          <div className={`text-center mb-12 sm:mb-16 transition-opacity duration-200 ${
            isLoaded && featuresInView ? 'opacity-100' : 'opacity-0'
          }`}>
            <h2 className="text-display-sm sm:text-display-md lg:text-display-lg mb-4">
              Fitur Lengkap untuk Tim Anda
            </h2>
            <p className="font-sans text-lg text-body max-w-2xl mx-auto">
              Semua yang Anda butuhkan untuk mengelola kehadiran tim remote work dalam satu platform
            </p>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 sm:gap-8">
            {features.map((feature, index) => (
              <div
                key={index}
                className={`card p-6 sm:p-8 transition-all duration-200 hover:shadow-brutal-xl hover:-translate-x-0.5 hover:-translate-y-0.5 ${
                  isLoaded && featuresInView ? 'opacity-100' : 'opacity-0'
                }`}
                style={{ transitionDelay: `${300 + index * 100}ms` }}
              >
                <div className="flex items-center justify-between mb-6">
                  <span className="font-display font-bold text-display-md text-hairline-strong/30">{String(index + 1).padStart(2, '0')}</span>
                  <div className={`w-12 h-12 rounded-xl border-2 border-ink ${tileAccents[index % tileAccents.length]} flex items-center justify-center shadow-brutal-sm`}>
                    <feature.icon className="w-5 h-5 text-white" />
                  </div>
                </div>
                <h3 className="text-title-md mb-2">
                  {feature.title}
                </h3>
                <p className="font-sans text-body leading-relaxed">
                  {feature.description}
                </p>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* Testimonials Section */}
      <section ref={testimonialsRef} id="testimonials" className="py-16 sm:py-24 lg:py-section px-4 sm:px-6 lg:px-8 border-t-2 border-ink bg-surface-soft">
        <div className="max-w-7xl mx-auto">
          <div className={`text-center mb-12 sm:mb-16 transition-opacity duration-200 ${
            isLoaded && testimonialsInView ? 'opacity-100' : 'opacity-0'
          }`}>
            <h2 className="text-display-sm sm:text-display-md lg:text-display-lg mb-4">
              Dipercaya oleh Perusahaan Indonesia
            </h2>
            <p className="font-sans text-lg text-body max-w-2xl mx-auto">
              Lihat apa kata mereka tentang WFH Attendance System
            </p>
          </div>

          <div className="max-w-4xl mx-auto">
            <div className={`relative transition-opacity duration-200 ${
              isLoaded && testimonialsInView ? 'opacity-100' : 'opacity-0'
            }`}>
              <div className="card p-6 sm:p-10">
                <div className="flex items-center space-x-1 mb-4">
                  {renderStars(testimonials[activeTestimonial].rating)}
                </div>
                <blockquote className="font-sans text-lg sm:text-xl text-body-strong mb-6">
                  &ldquo;{testimonials[activeTestimonial].content}&rdquo;
                </blockquote>
                <div className="flex items-center space-x-4">
                  <div className="w-12 h-12 rounded-xl border-2 border-ink bg-accent flex items-center justify-center font-display font-bold text-sm text-white flex-shrink-0 shadow-brutal-sm">
                    {getInitials(testimonials[activeTestimonial].name)}
                  </div>
                  <div>
                    <div className="font-display font-bold text-body-strong">
                      {testimonials[activeTestimonial].name}
                    </div>
                    <div className="caption-uppercase mt-1">
                      {testimonials[activeTestimonial].role}
                    </div>
                  </div>
                </div>
              </div>

              <div className="flex justify-center space-x-2 mt-6">
                {testimonials.map((_, index) => (
                  <button
                    key={index}
                    onClick={() => setActiveTestimonial(index)}
                    aria-label={`Testimoni ${index + 1}`}
                    className={`h-3 rounded-full border-2 border-ink transition-all duration-200 ${
                      index === activeTestimonial ? 'w-8 bg-primary' : 'w-3 bg-white'
                    }`}
                  />
                ))}
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* Open Source Section */}
      <section ref={ctaRef} id="opensource" className="py-16 sm:py-24 lg:py-section px-4 sm:px-6 lg:px-8 border-t-2 border-ink">
        <div className="max-w-7xl mx-auto">
          <div className={`text-center mb-12 sm:mb-16 transition-opacity duration-200 ${
            isLoaded && ctaInView ? 'opacity-100' : 'opacity-0'
          }`}>
            <h2 className="text-display-sm sm:text-display-md lg:text-display-lg mb-4">
              100% Gratis &amp; Open Source
            </h2>
            <p className="font-sans text-lg text-body max-w-2xl mx-auto">
              Kami percaya bahwa tools untuk remote work harus bisa diakses oleh semua perusahaan
            </p>
          </div>

          <div className="grid lg:grid-cols-2 gap-8 lg:gap-12 items-center">
            <div className="space-y-6">
              {[
                {
                  icon: CheckCircle,
                  title: 'Tanpa Biaya Berlangganan',
                  description: 'Tidak ada biaya setup, bulanan, atau per pengguna. Benar-benar gratis.'
                },
                {
                  icon: Lock,
                  title: 'Full Source Code Access',
                  description: 'Customize sesuai kebutuhan bisnis Anda dengan full source code.'
                },
                {
                  icon: Users,
                  title: 'Community Driven',
                  description: 'Dikembangkan bersama community, dengan update berkala dan bug fixes.'
                },
                {
                  icon: Shield,
                  title: 'Self-Hosted',
                  description: 'Data perusahaan Anda aman karena bisa di-host di server sendiri.'
                }
              ].map((item, index) => (
                <div key={index} className={`flex items-start space-x-4 rounded-card border-2 border-ink bg-white p-5 shadow-brutal transition-opacity duration-200 ${
                  isLoaded && ctaInView ? 'opacity-100' : 'opacity-0'
                }`}>
                  <div className={`w-11 h-11 rounded-xl border-2 border-ink ${tileAccents[index % tileAccents.length]} flex items-center justify-center flex-shrink-0 shadow-brutal-sm`}>
                    <item.icon className="w-5 h-5 text-white" />
                  </div>
                  <div>
                    <h3 className="text-title-sm mb-1">{item.title}</h3>
                    <p className="font-sans text-body">{item.description}</p>
                  </div>
                </div>
              ))}
            </div>

            <div className="rounded-card-lg border-2 border-ink bg-ink text-white p-8 sm:p-10 text-center space-y-6 shadow-brutal-xl">
              <div className="w-16 h-16 rounded-2xl border-2 border-white bg-white/10 flex items-center justify-center mx-auto">
                <Github className="w-8 h-8 text-white" />
              </div>
              <div>
                <h3 className="font-display font-bold text-display-sm text-white mb-2">Contribute to Project</h3>
                <p className="font-sans text-white/70">
                  Bergabunglah dengan kami dalam mengembangkan sistem kehadiran terbaik untuk Indonesia
                </p>
              </div>
              <a
                href="https://github.com/tsdlamongan/WFH-Attendance-System"
                target="_blank"
                rel="noopener noreferrer"
                className="btn-primary w-full"
              >
                <Github className="w-5 h-5" />
                <span>View on GitHub</span>
                <ExternalLink className="w-4 h-4" />
              </a>
            </div>
          </div>
        </div>
      </section>

      {/* CTA Section */}
      <section className="py-16 sm:py-24 lg:py-section px-4 sm:px-6 lg:px-8 border-t-2 border-ink bg-surface-soft">
        <div className="max-w-4xl mx-auto">
          <div className="rounded-card-lg border-2 border-ink bg-primary p-8 sm:p-12 text-center shadow-brutal-xl">
            <h2 className="font-display font-bold text-display-md sm:text-display-lg text-white mb-6">
              Siap Memulai?
            </h2>
            <p className="font-sans text-lg sm:text-xl text-white/90 mb-8">
              Bergabunglah dengan ratusan perusahaan yang sudah menggunakan WFH Attendance System
            </p>
            <div className="flex flex-col sm:flex-row gap-4 justify-center">
              <Link
                to="/register"
                className="inline-flex items-center justify-center gap-2 rounded-full border-2 border-ink bg-white px-7 py-3 min-h-[44px] font-display text-button text-ink shadow-brutal transition-all duration-200 hover:shadow-brutal-lg hover:-translate-x-0.5 hover:-translate-y-0.5 active:shadow-brutal-sm active:translate-x-0 active:translate-y-0"
              >
                <span>Daftar Gratis Sekarang</span>
                <ArrowRight className="w-5 h-5" />
              </Link>
              <a
                href="https://github.com/tsdlamongan/WFH-Attendance-System"
                target="_blank"
                rel="noopener noreferrer"
                className="inline-flex items-center justify-center gap-2 rounded-full border-2 border-ink bg-transparent px-7 py-3 min-h-[44px] font-display text-button text-ink transition-all duration-200 hover:bg-white/40"
              >
                <Github className="w-5 h-5" />
                <span>Star on GitHub</span>
              </a>
            </div>
          </div>
        </div>
      </section>

      {/* Footer */}
      <footer className="bg-ink text-white/70 border-t-2 border-ink py-12 sm:py-16 px-4 sm:px-6 lg:px-8">
        <div className="max-w-7xl mx-auto">
          <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-8 sm:gap-10">
            <div className="space-y-4">
              <div className="flex items-center space-x-3">
                <div className="w-9 h-9 rounded-lg border-2 border-white bg-primary flex items-center justify-center">
                  <Clock className="w-4 h-4 text-white" />
                </div>
                <span className="font-display font-extrabold text-wordmark text-white">WFH Attendance</span>
              </div>
              <p className="font-sans text-sm text-white/60">
                Sistem kehadiran remote work gratis dan open source untuk perusahaan Indonesia.
              </p>
            </div>

            <div className="space-y-4">
              <h4 className="font-display text-caption uppercase text-white">Product</h4>
              <ul className="space-y-3">
                <li><button onClick={() => handleNavClick('features')} className="font-sans text-nav-link text-white/60 hover:text-white transition-colors">Features</button></li>
                <li><button onClick={() => handleNavClick('testimonials')} className="font-sans text-nav-link text-white/60 hover:text-white transition-colors">Testimonials</button></li>
                <li><Link to="/login" className="font-sans text-nav-link text-white/60 hover:text-white transition-colors">Login</Link></li>
                <li><Link to="/register" className="font-sans text-nav-link text-white/60 hover:text-white transition-colors">Register</Link></li>
              </ul>
            </div>

            <div className="space-y-4">
              <h4 className="font-display text-caption uppercase text-white">Resources</h4>
              <ul className="space-y-3">
                <li>
                  <a
                    href="https://github.com/tsdlamongan/WFH-Attendance-System"
                    target="_blank"
                    rel="noopener noreferrer"
                    className="font-sans text-nav-link text-white/60 hover:text-white transition-colors flex items-center space-x-1"
                  >
                    <Github className="w-3 h-3" />
                    <span>GitHub</span>
                  </a>
                </li>
                <li>
                  <a
                    href="https://github.com/tsdlamongan/WFH-Attendance-System/blob/main/README.md"
                    target="_blank"
                    rel="noopener noreferrer"
                    className="font-sans text-nav-link text-white/60 hover:text-white transition-colors flex items-center space-x-1"
                  >
                    <ExternalLink className="w-3 h-3" />
                    <span>Documentation</span>
                  </a>
                </li>
                <li>
                  <a
                    href="https://github.com/tsdlamongan/WFH-Attendance-System/issues"
                    target="_blank"
                    rel="noopener noreferrer"
                    className="font-sans text-nav-link text-white/60 hover:text-white transition-colors flex items-center space-x-1"
                  >
                    <ExternalLink className="w-3 h-3" />
                    <span>Support</span>
                  </a>
                </li>
              </ul>
            </div>

            <div className="space-y-4">
              <h4 className="font-display text-caption uppercase text-white">Legal</h4>
              <ul className="space-y-3">
                <li>
                  <a
                    href="https://github.com/tsdlamongan/WFH-Attendance-System/blob/main/LICENSE"
                    target="_blank"
                    rel="noopener noreferrer"
                    className="font-sans text-nav-link text-white/60 hover:text-white transition-colors"
                  >
                    License (MIT)
                  </a>
                </li>
                <li>
                  <a
                    href="https://github.com/tsdlamongan/WFH-Attendance-System/blob/main/.github/PRIVACY.md"
                    target="_blank"
                    rel="noopener noreferrer"
                    className="font-sans text-nav-link text-white/60 hover:text-white transition-colors"
                  >
                    Privacy Policy
                  </a>
                </li>
                <li>
                  <a
                    href="https://github.com/tsdlamongan/WFH-Attendance-System/blob/main/.github/TERMS.md"
                    target="_blank"
                    rel="noopener noreferrer"
                    className="font-sans text-nav-link text-white/60 hover:text-white transition-colors"
                  >
                    Terms of Service
                  </a>
                </li>
              </ul>
            </div>
          </div>

          <div className="border-t border-white/15 mt-8 pt-8 flex flex-col sm:flex-row justify-between items-center">
            <p className="font-sans text-xs text-white/50">
              © 2025 WFH Attendance System. Coded by ❤️ Project open source dari PT Teknologi Sunan Drajat Lamongan untuk Indonesia.
            </p>
            <div className="flex items-center space-x-4 mt-4 sm:mt-0">
              <a
                href="https://github.com/tsdlamongan/WFH-Attendance-System"
                target="_blank"
                rel="noopener noreferrer"
                className="text-white/60 hover:text-white transition-colors"
              >
                <Github className="w-5 h-5" />
              </a>
            </div>
          </div>
        </div>
      </footer>

      </div>
  );
};
