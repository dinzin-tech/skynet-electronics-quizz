import React from 'react';
import { BrandBanner } from './BrandBanner';

interface NavbarProps {
  currentWeekLabel?: string;
  totalWeeks?: number;
  statusBadge?: string;
  onLogout?: () => void;
  children?: React.ReactNode;
}

export const Navbar: React.FC<NavbarProps> = ({
  currentWeekLabel,
  totalWeeks,
  statusBadge,
  onLogout,
  children,
}) => {
  return (
    <BrandBanner
      currentWeekLabel={currentWeekLabel}
      totalWeeks={totalWeeks}
      statusBadge={statusBadge}
      onLogout={onLogout}
    >
      {children}
    </BrandBanner>
  );
};
