import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import './index.css';
import App from './App';
import { prefetchFeaturedProperties } from './services/propertyService';

// Warm the landing page's featured-properties request before React starts, so it
// overlaps with the initial bundle download and hydration instead of queueing
// behind them. Home joins this same request (see the de-dup in propertyService).
prefetchFeaturedProperties();

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <BrowserRouter>
      <App />
    </BrowserRouter>
  </StrictMode>
);
