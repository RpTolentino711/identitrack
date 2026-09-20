'use client';

import React, { useContext } from 'react';
import { AppContext } from '../context/AppContext';
import { useRouter } from 'next/navigation';
import { useEffect } from 'react';
import Layout from '../components/Layout';
import PageTabs from '../components/PageTabs';

const DashboardPage: React.FC = () => {
  const { user } = useContext(AppContext);
  const router = useRouter();

  useEffect(() => {
    if (!user) {
      router.push('/login');
    }
  }, [user, router]);

  if (!user) {
    return (
      <div className="min-h-screen flex items-center justify-center">
        <div className="animate-spin rounded-full h-32 w-32 border-b-2 border-primary"></div>
      </div>
    );
  }

  return (
    <Layout>
      <div className="space-y-6">
        <PageTabs />
        
        <div className="bg-card border border-border rounded-lg p-6">
          <h1 className="text-2xl font-bold text-text-primary mb-4">Welcome to Student Violation System</h1>
          <p className="text-text-secondary">
            Manage student violations, track records, and analyze patterns with AI-powered insights.
          </p>
        </div>
        
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
          <div className="bg-card border border-border rounded-lg p-4">
            <h3 className="text-lg font-semibold text-text-primary">Quick Actions</h3>
            <p className="text-sm text-text-secondary mt-2">Access common tasks and features</p>
          </div>
          <div className="bg-card border border-border rounded-lg p-4">
            <h3 className="text-lg font-semibold text-text-primary">Recent Activity</h3>
            <p className="text-sm text-text-secondary mt-2">View latest violations and updates</p>
          </div>
          <div className="bg-card border border-border rounded-lg p-4">
            <h3 className="text-lg font-semibold text-text-primary">Statistics</h3>
            <p className="text-sm text-text-secondary mt-2">Overview of system data</p>
          </div>
          <div className="bg-card border border-border rounded-lg p-4">
            <h3 className="text-lg font-semibold text-text-primary">Reports</h3>
            <p className="text-sm text-text-secondary mt-2">Generate and view reports</p>
          </div>
        </div>
      </div>
    </Layout>
  );
};

export default DashboardPage;
