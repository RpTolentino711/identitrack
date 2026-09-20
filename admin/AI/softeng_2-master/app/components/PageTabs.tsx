'use client';

import React from 'react';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { DashboardIcon, ViolationsIcon, RecordIcon, StudentsIcon, HistoryIcon } from './Icons';

const PageTabs: React.FC = () => {
  const pathname = usePathname();
  
  const tabs = [
    { to: "/dashboard", icon: DashboardIcon, label: "Dashboard" },
    { to: "/violations", icon: ViolationsIcon, label: "Violations" },
    { to: "/record", icon: RecordIcon, label: "Record" },
    { to: "/students", icon: StudentsIcon, label: "Students" },
    { to: "/history", icon: HistoryIcon, label: "History" },
  ];

  return (
    <div className="mb-6">
      <div className="border-b border-border">
        <nav className="-mb-px flex space-x-4" aria-label="Tabs">
          {tabs.map(tab => {
            const isActive = pathname === tab.to;
            return (
              <Link
                key={tab.to}
                href={tab.to}
                className={`whitespace-nowrap py-3 px-1 border-b-2 font-medium text-sm transition-colors ${
                  isActive 
                    ? 'border-primary text-primary' 
                    : 'border-transparent text-text-secondary hover:text-text-primary hover:border-gray-500'
                }`}
              >
                <tab.icon className="w-5 h-5 mr-2 inline-block"/>
                {tab.label}
              </Link>
            );
          })}
        </nav>
      </div>
    </div>
  );
};

export default PageTabs;
