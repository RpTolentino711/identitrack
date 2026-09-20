'use client';

import React, { useContext, useMemo } from 'react';
import { AppContext } from '../context/AppContext';
import PageTabs from '../components/PageTabs';
import Layout from '../components/Layout';
import { Violation, Severity } from '../types';

const SEVERITY_STYLES: Record<Severity, { text: string; bg: string }> = {
    'Low': { text: 'text-green-400', bg: 'bg-green-500/10' },
    'Medium': { text: 'text-yellow-400', bg: 'bg-yellow-500/10' },
    'High': { text: 'text-orange-400', bg: 'bg-orange-500/10' },
    'Critical': { text: 'text-red-500', bg: 'bg-red-600/10' },
};

const LikelihoodGuideBar: React.FC<{ color: string; range: string; label: string; description: string; }> = ({ color, range, label, description }) => (
    <div className="flex items-start space-x-3">
        <div className="flex-shrink-0">
            <div className={`w-3 h-3 rounded-full mt-1.5 ${color}`}></div>
        </div>
        <div>
            <p className="text-sm font-bold text-text-primary">{range} - {label}</p>
            <p className="text-xs text-text-secondary">{description}</p>
        </div>
    </div>
);

const ViolationsPage: React.FC = () => {
    const { students } = useContext(AppContext);

    const allViolations = useMemo(() => {
        return students
            .flatMap(student => (student.violations || []).map(v => ({...v, studentName: student.name})))
            .sort((a,b) => new Date(b.occurred_at).getTime() - new Date(a.occurred_at).getTime());
    }, [students]);

    const stats = useMemo(() => {
        const totalStudents = students.length;
        const totalViolations = allViolations.length;
        const severityCounts = allViolations.reduce((acc, v) => {
            acc[v.severity] = (acc[v.severity] || 0) + 1;
            return acc;
        }, {} as Record<Severity, number>);

        const avgRisk = totalViolations > 0
            ? (allViolations.reduce((sum, v) => sum + v.likelihood, 0) / totalViolations).toFixed(0)
            : 0;

        return {
            totalStudents,
            totalViolations,
            unassigned: 0, // Placeholder
            active: 0, // Placeholder
            completed: 0, // Placeholder
            low: severityCounts['Low'] || 0,
            medium: severityCounts['Medium'] || 0,
            high: severityCounts['High'] || 0,
            critical: severityCounts['Critical'] || 0,
            avgRisk: `${avgRisk}%`
        };
    }, [students, allViolations]);

    const recentViolations = allViolations.slice(0, 5);

    return (
        <Layout>
            <div className="space-y-6">
                <PageTabs />

            {/* Top Stats */}
            <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4">
                <StatCard title="Students" value={stats.totalStudents} color="text-blue-400" />
                <StatCard title="Violations" value={stats.totalViolations} color="text-yellow-400" />
                <StatCard title="Unassigned" value={stats.unassigned} color="text-gray-400" />
                <StatCard title="Active" value={stats.active} color="text-orange-400" />
                <StatCard title="Completed" value={stats.completed} color="text-green-400" />
            </div>

            {/* Severity Cards */}
            <div className="bg-card border border-border rounded-lg p-4">
                <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 text-center">
                    <SeverityStat title="Total" value={stats.totalViolations} icon="🔵" />
                    <SeverityStat title="Low" value={stats.low} icon="🟢" />
                    <SeverityStat title="Medium" value={stats.medium} icon="🟡" />
                    <SeverityStat title="High" value={stats.high} icon="🟠" />
                    <SeverityStat title="Critical" value={stats.critical} icon="🔴" />
                    <SeverityStat title="Avg. Risk" value={stats.avgRisk} icon="📈" />
                </div>
            </div>
            
            {/* Recent Violations */}
             <div className="bg-card border border-border rounded-lg p-6">
                {recentViolations.length > 0 ? (
                     <div className="space-y-4">
                        {recentViolations.map(v => (
                            <div key={v.id} className="p-3 bg-secondary/50 rounded-md border border-border/50">
                                <div className="flex justify-between items-start">
                                    <div>
                                        <p className="text-text-primary font-semibold">{v.studentName}</p>
                                        <p className="text-sm text-text-secondary mt-1">{v.description}</p>
                                    </div>
                                    <div className="text-right flex-shrink-0">
                                        <span className={`px-2 py-1 text-xs font-semibold rounded-full border ${SEVERITY_STYLES[v.severity].bg} ${SEVERITY_STYLES[v.severity].text}`}>{v.severity}</span>
                                        <p className="text-xs text-text-secondary mt-1">{new Date(v.occurred_at).toLocaleDateString()}</p>
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                ) : (
                    <div className="text-center py-10">
                        <svg className="mx-auto h-12 w-12 text-gray-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        <p className="mt-4 text-text-primary font-semibold">No violations found.</p>
                        <p className="text-sm text-text-secondary">Record a new violation to see it here.</p>
                    </div>
                )}
            </div>

            {/* Likelihood Guide */}
            <div className="bg-card border border-border rounded-lg p-6">
                <h3 className="text-base font-semibold text-text-primary mb-4 flex items-center">
                    <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5 mr-2 text-text-secondary" viewBox="0 0 20 20" fill="currentColor"><path fillRule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clipRule="evenodd" /></svg>
                    Confidence Level Guide
                </h3>
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
                    <LikelihoodGuideBar color="bg-green-500" range="0-24%" label="Low Confidence" description="Uncertain prediction" />
                    <LikelihoodGuideBar color="bg-yellow-500" range="25-49%" label="Moderate Confidence" description="Somewhat reliable" />
                    <LikelihoodGuideBar color="bg-orange-500" range="50-74%" label="High Confidence" description="Reliable prediction" />
                    <LikelihoodGuideBar color="bg-red-600" range="75-100%" label="Very High Confidence" description="Highly reliable prediction" />
                </div>
            </div>

            </div>
        </Layout>
    );
};

const StatCard: React.FC<{title: string; value: string|number; color: string}> = ({title, value, color}) => (
    <div className="bg-card border border-border p-4 rounded-lg">
        <p className={`text-3xl font-bold ${color}`}>{value}</p>
        <p className="text-sm text-text-secondary">{title}</p>
    </div>
);

const SeverityStat: React.FC<{title: string; value: string|number; icon: string}> = ({title, value, icon}) => (
    <div>
        <p className="text-2xl font-bold text-text-primary">{value}</p>
        <p className="text-sm text-text-secondary flex items-center justify-center">
            <span className="mr-1">{icon}</span> {title}
        </p>
    </div>
)

export default ViolationsPage;
