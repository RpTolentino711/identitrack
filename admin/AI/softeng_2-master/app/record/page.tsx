'use client';

import React, { useState, useContext } from 'react';
import { AppContext } from '../context/AppContext';
import { ViolationPrediction, Severity } from '../types';
import { getViolationPrediction } from '../services/predictionService';
import { Spinner } from '../components/Icons';
import PageTabs from '../components/PageTabs';
import Layout from '../components/Layout';
import { CATEGORY_VIOLATIONS } from './violationsMapping';

const SEVERITY_STYLES: Record<Severity, { bg: string; text: string; ring: string; border: string }> = {
    'Low': { bg: 'bg-green-500/10', text: 'text-green-400', ring: 'ring-green-500/20', border: 'border-green-500/30' },
    'Medium': { bg: 'bg-yellow-500/10', text: 'text-yellow-400', ring: 'ring-yellow-500/20', border: 'border-yellow-500/30' },
    'High': { bg: 'bg-orange-500/10', text: 'text-orange-400', ring: 'ring-orange-500/20', border: 'border-orange-500/30' },
    'Critical': { bg: 'bg-red-600/10', text: 'text-red-500', ring: 'ring-red-600/20', border: 'border-red-600/30' },
};

const OFFENSE_NUMBERS = ['1st Offense', '2nd Offense', '3rd Offense'];

const RecordPage: React.FC = () => {
    const { students, addViolationToStudent } = useContext(AppContext);
    const [selectedStudentId, setSelectedStudentId] = useState<string>('');
    const [category, setCategory] = useState('');
    const [violation, setViolation] = useState('');
    const [numberOfOffense, setNumberOfOffense] = useState('');
    const [description, setDescription] = useState('');
    
    const [prediction, setPrediction] = useState<ViolationPrediction | null>(null);
    const [isAnalyzing, setIsAnalyzing] = useState(false);
    
    const handleCategoryChange = (e: React.ChangeEvent<HTMLSelectElement>) => {
        setCategory(e.target.value);
        setViolation(''); // Reset violation when category changes
        setPrediction(null);
    };

    const handlePredict = async () => {
        if (!category || !violation || !numberOfOffense || !description.trim()) {
            alert('Please fill out all fields before predicting.');
            return;
        }
        setIsAnalyzing(true);
        const result = await getViolationPrediction(description, category, violation, numberOfOffense);
        setPrediction(result);
        setIsAnalyzing(false);
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!selectedStudentId) {
            alert('Please select a student.');
            return;
        }
        if (!prediction) {
            alert('Please predict the sanction before submitting.');
            return;
        }

        addViolationToStudent(selectedStudentId, {
          description,
          category: prediction.category,
          severity: prediction.severity,
          likelihood: Math.round(prediction.confidence_level ?? 0),
          suggested_sanction: prediction.sanction,
        });
        const studentName = students.find(s => s.student_id === selectedStudentId)?.name;
        alert(`Violation recorded for ${studentName ?? selectedStudentId}.`);
        
        // Reset form
        setSelectedStudentId('');
        setCategory('');
        setViolation('');
        setNumberOfOffense('');
        setDescription('');
        setPrediction(null);
    };

    const availableViolations = category ? CATEGORY_VIOLATIONS[category] || [] : [];

    return (
        <Layout>
            <div>
                <PageTabs />
            <form onSubmit={handleSubmit} className="space-y-6 max-w-4xl mx-auto">
                <div className="bg-card border border-border rounded-lg">
                    <div className="p-4 border-b border-border">
                        <h3 className="text-lg font-semibold text-text-primary">Record Violation</h3>
                    </div>
                    <div className="p-6 space-y-4">
                        <div>
                            <label htmlFor="student-select" className="block text-sm font-medium text-text-secondary">Select Student *</label>
                            <select
                                id="student-select"
                                value={selectedStudentId}
                                onChange={(e) => setSelectedStudentId(e.target.value)}
                                required
                                className="mt-1 block w-full pl-3 pr-10 py-2 bg-background border border-border rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary sm:text-sm"
                            >
                                <option value="" disabled>Choose a student...</option>
                                {students.map(student => (
                                    <option key={student.student_id} value={student.student_id}>{student.name} ({student.student_id})</option>
                                ))}
                            </select>
                        </div>
                        
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label htmlFor="category" className="block text-sm font-medium text-text-secondary">Category *</label>
                                <select
                                    id="category"
                                    value={category}
                                    onChange={handleCategoryChange}
                                    required
                                    className="mt-1 block w-full pl-3 pr-10 py-2 bg-background border border-border rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary sm:text-sm"
                                >
                                    <option value="" disabled>Select category...</option>
                                    {Object.keys(CATEGORY_VIOLATIONS).map(cat => (
                                        <option key={cat} value={cat}>{cat}</option>
                                    ))}
                                </select>
                            </div>
                            
                            <div>
                                <label htmlFor="numberOfOffense" className="block text-sm font-medium text-text-secondary">Number of Offense *</label>
                                <select
                                    id="numberOfOffense"
                                    value={numberOfOffense}
                                    onChange={(e) => {
                                        setNumberOfOffense(e.target.value);
                                        setPrediction(null);
                                    }}
                                    required
                                    className="mt-1 block w-full pl-3 pr-10 py-2 bg-background border border-border rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary sm:text-sm"
                                >
                                    <option value="" disabled>Select number of offense...</option>
                                    {OFFENSE_NUMBERS.map(offense => (
                                        <option key={offense} value={offense}>{offense}</option>
                                    ))}
                                </select>
                            </div>
                        </div>

                        <div>
                            <label htmlFor="violation" className="block text-sm font-medium text-text-secondary">Violation *</label>
                            <select
                                id="violation"
                                value={violation}
                                onChange={(e) => {
                                    setViolation(e.target.value);
                                    setPrediction(null);
                                }}
                                required
                                disabled={!category}
                                className="mt-1 block w-full pl-3 pr-10 py-2 bg-background border border-border rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary sm:text-sm"
                            >
                                <option value="" disabled>{category ? 'Select violation...' : 'Select a category first...'}</option>
                                {availableViolations.map(viol => (
                                    <option key={viol} value={viol}>{viol}</option>
                                ))}
                            </select>
                        </div>

                        <div>
                            <label htmlFor="description" className="block text-sm font-medium text-text-secondary">Scenario Description *</label>
                            <textarea
                                id="description"
                                rows={4}
                                value={description}
                                onChange={(e) => {
                                    setDescription(e.target.value);
                                    setPrediction(null);
                                }}
                                required
                                className="mt-1 block w-full px-3 py-2 bg-background border border-border rounded-md shadow-sm placeholder-text-secondary/60 focus:outline-none focus:ring-primary focus:border-primary sm:text-sm"
                                placeholder="Describe what happened in detail..."
                            />
                        </div>

                        <div className="flex justify-end pt-2">
                             <button
                                type="button"
                                onClick={handlePredict}
                                disabled={isAnalyzing || !category || !violation || !numberOfOffense || !description.trim()}
                                className="flex items-center px-4 py-2 bg-secondary text-primary font-semibold rounded-md border border-border hover:bg-secondary/80 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                {isAnalyzing ? (
                                    <><Spinner className="w-4 h-4 mr-2" /> Analyzing...</>
                                ) : (
                                    "Predict Sanction"
                                )}
                            </button>
                        </div>

                        {prediction && !isAnalyzing && (
                            <div className={`bg-secondary p-4 rounded-lg border ${SEVERITY_STYLES[prediction.severity].border} space-y-4 animate-fade-in`}>
                                <h3 className="font-semibold text-text-primary">Analysis Result</h3>
                                <div className="grid grid-cols-1 md:grid-cols-2 gap-4 text-center">
                                    <div>
                                        <p className="text-sm text-text-secondary">Severity</p>
                                        <span className={`inline-flex items-center rounded-md px-2 py-1 text-md font-medium ${SEVERITY_STYLES[prediction.severity].bg} ${SEVERITY_STYLES[prediction.severity].text} ring-1 ring-inset ${SEVERITY_STYLES[prediction.severity].ring}`}>
                                            {prediction.severity}
                                        </span>
                                    </div>
                                    <div>
                                        <p className="text-sm text-text-secondary">Confidence Level</p>
                                        <p className="font-bold text-lg text-primary">{prediction.confidence_level}%</p>
                                    </div>
                                </div>
                                {prediction.sanction && (
                                    <div className="pt-2 border-t border-border text-center">
                                        <p className="text-sm text-text-secondary">Recommended Sanction</p>
                                        <p className="font-bold text-lg text-primary">{prediction.sanction}</p>
                                    </div>
                                )}
                            </div>
                        )}
                    </div>
                </div>
                 <div className="flex justify-end">
                    <button
                        type="submit"
                        disabled={!prediction || isAnalyzing || !selectedStudentId}
                        className="flex items-center px-6 py-2 bg-red-600 text-white font-semibold rounded-md hover:bg-red-700 transition-colors disabled:bg-gray-500 disabled:cursor-not-allowed"
                    >
                         <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5 mr-2" viewBox="0 0 20 20" fill="currentColor">
                           <path fillRule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.21 3.03-1.742 3.03H4.42c-1.532 0-2.492-1.696-1.742-3.03l5.58-9.92zM10 13a1 1 0 110-2 1 1 0 010 2zm-1.75-5.75a.75.75 0 00-1.5 0v3a.75.75 0 001.5 0v-3z" clipRule="evenodd" />
                         </svg>
                        Record Violation
                    </button>
                </div>
            </form>
            </div>
        </Layout>
    );
};

export default RecordPage;
