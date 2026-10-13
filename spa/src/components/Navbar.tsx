import React from 'react';
import { BrandBanner } from './BrandBanner';

interface NavbarProps {
  currentWeekLabel?: string;
  totalWeeks?: number;
  statusBadge?: string;
  onLogout?: () => void;
  children?: React.ReactNode;
  hideBadges?: boolean;
}

export const Navbar: React.FC<NavbarProps> = ({
  currentWeekLabel,
  totalWeeks,
  statusBadge,
  onLogout,
  children,
  hideBadges = false,
}) => {
  return (
    <BrandBanner
      currentWeekLabel={currentWeekLabel}
      totalWeeks={totalWeeks}
      statusBadge={statusBadge}
      onLogout={onLogout}
      hideBadges={hideBadges}
    >
      {children}
    </BrandBanner>
  );
};
