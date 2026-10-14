import React from 'react';
import { getStoredUser, clearStoredAuth } from '../services/api';

interface BrandBannerProps {
  currentWeekLabel?: string;
  totalWeeks?: number;
  statusBadge?: string;
  onLogout?: () => void;
  children?: React.ReactNode;
  compact?: boolean;
  hideBadges?: boolean;
}

export const BrandBanner: React.FC<BrandBannerProps> = ({
  currentWeekLabel,
  totalWeeks = 5,
  statusBadge,
  onLogout,
  children,
  compact = false,
  hideBadges = false,
}) => {
  const user = getStoredUser();

  const handleLogout = () => {
    clearStoredAuth();
    if (onLogout) {
      onLogout();
    } else {
      window.location.reload();
    }
  };

  const challengeBadgeText = totalWeeks ? `${totalWeeks}-WEEK CHALLENGE` : 'WEEKLY CHALLENGE';
  const displayWeekLabel = currentWeekLabel || 'WEEK - 1';

  return (
    <header className={`brand-header-banner ${compact ? 'is-compact' : ''}`}>
      <div className="brand-header-inner">
        {/* Left Branding: OPPO + PEAK PURSUIT 4.0 */}
        <div className="brand-logo-cluster">
          <div className="brand-logo-wrapper">
            <img
              src="/app/logo.png"
              alt="PEAK PURSUIT 4.0"
              className="brand-logo-img"
              onError={(e) => {
                (e.target as HTMLImageElement).src = '/logo.png';
              }}
            />
          </div>
        </div>

        {/* Center / Right Status & Navigation */}
        <div className="brand-meta-cluster">
          {/* Dynamic Week & Status Badges */}
          {!hideBadges && (
            <div className="brand-badge-row">
              <div className="brand-badge-main-group">
                {displayWeekLabel && (
                  <span className="pill-badge pill-week">
                    <span className="pill-dot"></span>
                    {displayWeekLabel}
                  </span>
                )}

                {statusBadge && (
                  <span className="pill-badge pill-status">
                    {statusBadge}
                  </span>
                )}
              </div>

              <span className="pill-badge pill-challenge">
                {challengeBadgeText}
              </span>
            </div>
          )}

          {/* Children (e.g. Timer, Question Progress) */}
          {children && <div className="brand-header-children">{children}</div>}

          {/* User Session Info / Sign Out */}
          {user && (
            <div className="brand-user-wrapper">
              <div className="brand-user-details hidden-xs">
                <div className="brand-user-name">{user.name}</div>
                <div className="brand-user-code">{user.employee_code}</div>
              </div>
              <button
                onClick={handleLogout}
                className="btn-banner-logout"
                title="Sign out of assessment"
              >
                Sign Out
              </button>
            </div>
          )}
        </div>
      </div>
    </header>
  );
};
