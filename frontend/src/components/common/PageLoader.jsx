import BrandLoader from './BrandLoader';

const PageLoader = ({ text = 'Chargement en cours...' }) => {
  return (
    <div className="flex flex-col items-center justify-center min-h-screen bg-white">
      <BrandLoader text={text} />
    </div>
  );
};

export default PageLoader;
