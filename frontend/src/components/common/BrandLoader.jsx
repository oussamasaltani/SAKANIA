const BrandLoader = ({ 
  size = 'default',
  showText = true,
  className = '',
  text = 'Chargement en cours...'
}) => {
  const sizeClasses = {
    small: {
      container: 'h-10 w-10',
      logo: 'h-6 w-6',
      ring: 'border-[1.5px]'
    },
    default: {
      container: 'h-16 w-16',
      logo: 'h-10 w-10',
      ring: 'border-2'
    },
    large: {
      container: 'h-24 w-24',
      logo: 'h-14 w-14',
      ring: 'border-2'
    }
  };

  const currentSize = sizeClasses[size] || sizeClasses.default;

  return (
    <div className={`flex flex-col items-center justify-center ${className}`}>
      <div className="relative flex items-center justify-center">
        <div 
          className={`absolute inset-0 rounded-full ${currentSize.ring} border-brand/20 border-t-brand animate-spin`}
        />
        <div className={`relative ${currentSize.container} flex items-center justify-center`}>
          <img 
            src="/Logo-sakania.png" 
            alt="Logo Sakania" 
            className={`object-contain ${currentSize.logo} drop-shadow-lg`}
          />
        </div>
      </div>
      {showText && (
        <p className="mt-4 text-xs font-semibold uppercase tracking-[0.2em] text-gray-400 animate-pulse">
          {text}
        </p>
      )}
    </div>
  );
};

export default BrandLoader;
