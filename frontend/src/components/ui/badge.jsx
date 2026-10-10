import * as React from 'react';
import { cva } from 'class-variance-authority';
import { cn } from '../../lib/utils';

const badgeVariants = cva(
  'inline-flex items-center gap-1 rounded-full text-xs font-semibold ring-1 ring-inset transition-colors',
  {
    variants: {
      variant: {
        default: 'bg-brand-soft text-brand-700 ring-brand/20',
        gradient: 'bg-brand-gradient text-white ring-transparent shadow-sm shadow-brand/20',
        secondary: 'bg-gray-100 text-gray-600 ring-gray-200/70',
        success: 'bg-emerald-50 text-emerald-700 ring-emerald-200/60',
        warning: 'bg-amber-50 text-amber-700 ring-amber-200/60',
        destructive: 'bg-red-50 text-red-600 ring-red-200/60',
        info: 'bg-blue-50 text-blue-700 ring-blue-200/60',
        outline: 'bg-white text-gray-600 ring-gray-200',
      },
    },
    defaultVariants: {
      variant: 'default',
    },
  }
);

function Badge({ className, variant, ...props }) {
  return <span className={cn(badgeVariants({ variant }), className)} {...props} />;
}

export { Badge, badgeVariants };
