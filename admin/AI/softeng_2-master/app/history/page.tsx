'use client';

import React, { useContext, useState, useMemo } from 'react';
import { AppContext } from '../context/AppContext';
import { Severity } from '../types';
import PageTabs from '../components/PageTabs';
import Layout from '../components/Layout';

const SEVERITY_BADGE_STYLES: Record<Severity, string> = {
    'Low': 'bg-green-500/20 text-green-400 border-green-500/30',
    'Medium': 'bg-yellow-500/20 text-yellow-400 border-yellow-500/30',
    'High': 'bg-orange-500/20 text-orange-400 border-orange-500/30',
    'Critical': 'bg-red-600/20 text-red-500 border-red-600/30',
};

const HistoryPage: React.FC = () => {
    const { students } = useContext(AppContext);
    const [selectedStudent, setSelectedStudent] = useState<string>('All Students');
    const [selectedSeverity, setSelectedSeverity] = useState<string>('All Levels');

    const allViolations = useMemo(() => {
        return students
            .flatMap(student => (student.violations || []).map(v => ({ ...v, studentName: student.name, studentId: student.student_id })))
            .sort((a, b) => new Date(b.occurred_at).getTime() - new Date(a.occurred_at).getTime());
    }, [students]);

    const filteredViolations = useMemo(() => {
        return allViolations.filter(v => {
            const studentMatch = selectedStudent === 'All Students' || v.studentId === selectedStudent;
            const severityMatch = selectedSeverity === 'All Levels' || v.severity === selectedSeverity;
            return studentMatch && severityMatch;
        });
    }, [allViolations, selectedStudent, selectedSeverity]);

    return (
        <Layout>
            <div className="space-y-6">
                <PageTabs />

            <div className="bg-card border border-border rounded-lg p-4">
                <div className="flex flex-col md:flex-row gap-4">
                    <div className="flex-1">
                        <label htmlFor="student-filter" className="block text-sm font-medium text-text-secondary">Student</label>
                        <select
                            id="student-filter"
                            value={selectedStudent}
                            onChange={e => setSelectedStudent(e.target.value)}
                            className="mt-1 block w-full pl-3 pr-10 py-2 bg-background border border-border rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary sm:text-sm"
                        >
                            <option>All Students</option>
                            {students.map(s => <option key={s.student_id} value={s.student_id}>{s.name}</option>)}
                        </select>
                    </div>
                    <div className="flex-1">
                        <label htmlFor="severity-filter" className="block text-sm font-medium text-text-secondary">Severity</label>
                        <select
                            id="severity-filter"
                            value={selectedSeverity}
                            onChange={e => setSelectedSeverity(e.target.value)}
                             className="mt-1 block w-full pl-3 pr-10 py-2 bg-background border border-border rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary sm:text-sm"
                        >
                            <option>All Levels</option>
                            <option>Low</option>
                            <option>Medium</option>
                            <option>High</option>
                            <option>Critical</option>
                        </select>
                    </div>
                </div>
            </div>

            <div className="bg-card border border-border rounded-lg">
                <div className="p-4 border-b border-border">
                    <h3 className="text-lg font-semibold text-text-primary">Violation History ({filteredViolations.length})</h3>
                    <p className="text-sm text-text-secondary">Review previously recorded violations and their sanctions.</p>
                </div>
                <div className="divide-y divide-border">
                    {filteredViolations.length > 0 ? (
                        filteredViolations.map(v => (
                            <div key={v.id} className="p-4 hover:bg-secondary/30">
                                <div className="flex justify-between items-start gap-4">
                                    <div className="flex-1">
                                        <p className="font-semibold text-text-primary">{v.studentName}</p>
                                        <p className="text-sm text-text-secondary mt-1">{v.description}</p>
                                        <p className="text-xs text-text-secondary/70 mt-2">{new Date(v.occurred_at).toLocaleString()}</p>
                                    </div>
                                    <div className="text-right flex-shrink-0">
                                        <span className={`px-2 py-1 text-xs font-semibold rounded-full border ${SEVERITY_BADGE_STYLES[v.severity]}`}>{v.severity}</span>
                                        <p className="text-xs text-text-secondary mt-1">{v.category} ({v.likelihood}%)</p>
                                    </div>
                                </div>
                            </div>
                        ))
                    ) : (
                        <div className="text-center p-12">
                             <svg className="mx-auto h-12 w-12 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                <path vectorEffect="non-scaling-stroke" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                            <h3 className="mt-2 text-sm font-medium text-text-primary">No violation history found.</h3>
                            <p className="mt-1 text-sm text-text-secondary">Try adjusting your filters or record a new violation.</p>
                        </div>
                    )}
                </div>
            </div>
            </div>
        </Layout>
    );
};

export default HistoryPage;
