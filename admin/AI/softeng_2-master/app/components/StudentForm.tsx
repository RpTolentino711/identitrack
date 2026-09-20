'use client';

import React, { useState, useEffect } from 'react';
import { Student } from '../types';

interface StudentFormProps {
  onSubmit: (studentData: Omit<Student, 'student_id' | 'violations' | 'sanctions' | 'created_at' | 'updated_at'>) => void;
  onCancel: () => void;
  studentToEdit?: Student | null;
}

const StudentForm: React.FC<StudentFormProps> = ({ onSubmit, studentToEdit, onCancel }) => {
  const [name, setName] = useState('');
  const [gradeLevel, setGradeLevel] = useState('');
  const [studentId, setStudentId] = useState('');
  const [section, setSection] = useState('');
  const [guardianContact, setGuardianContact] = useState('');

  useEffect(() => {
    if (studentToEdit) {
      setName(studentToEdit.name);
      setGradeLevel(studentToEdit.grade_level);
      setStudentId(studentToEdit.student_id);
      setSection(studentToEdit.section || '');
      setGuardianContact(studentToEdit.guardian_contact || '');
    } else {
      setName('');
      setGradeLevel('');
      setStudentId('');
      setSection('');
      setGuardianContact('');
    }
  }, [studentToEdit]);

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    // Do NOT include student_id in the payload; it's handled by caller (create) or path param (update)
    onSubmit({ name, grade_level: gradeLevel, section, guardian_contact: guardianContact } as any);
  };

  return (
    <form onSubmit={handleSubmit} className="p-4 bg-card rounded-b-lg border-x border-b border-border">
      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
         <div>
          <label htmlFor="studentId" className="block text-sm font-medium text-text-secondary">Student ID *</label>
          <input
            type="text"
            id="studentId"
            value={studentId}
            onChange={(e) => setStudentId(e.target.value)}
            required={!studentToEdit}
            disabled={!!studentToEdit}
            placeholder="e.g., 2024-001"
            className="mt-1 block w-full px-3 py-2 bg-background border border-border rounded-md shadow-sm placeholder-text-secondary/50 focus:outline-none focus:ring-primary focus:border-primary sm:text-sm disabled:opacity-60"
          />
        </div>
        <div>
          <label htmlFor="gradeLevel" className="block text-sm font-medium text-text-secondary">Grade Level *</label>
           <select
                id="gradeLevel"
                value={gradeLevel}
                onChange={(e) => setGradeLevel(e.target.value)}
                required
                className="mt-1 block w-full px-3 py-2 bg-background border border-border rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary sm:text-sm"
            >
                <option value="">Select grade level</option>
                <option value="Year 7">Year 7</option>
                <option value="Year 8">Year 8</option>
                <option value="Year 9">Year 9</option>
                <option value="Year 10">Year 10</option>
                <option value="Year 11">Year 11</option>
                <option value="Year 12">Year 12</option>
            </select>
        </div>
        <div className="col-span-1 md:col-span-2">
            <label htmlFor="name" className="block text-sm font-medium text-text-secondary">Full Name *</label>
            <input
                type="text"
                id="name"
                value={name}
                onChange={(e) => setName(e.target.value)}
                required
                placeholder="e.g., Juan Dela Cruz"
                className="mt-1 block w-full px-3 py-2 bg-background border border-border rounded-md shadow-sm placeholder-text-secondary/50 focus:outline-none focus:ring-primary focus:border-primary sm:text-sm"
            />
        </div>
        <div>
          <label htmlFor="section" className="block text-sm font-medium text-text-secondary">Section (Optional)</label>
          <input
            type="text"
            id="section"
            value={section}
            placeholder="e.g., A, Einstein, Rose"
            onChange={(e) => setSection(e.target.value)}
            className="mt-1 block w-full px-3 py-2 bg-background border border-border rounded-md shadow-sm placeholder-text-secondary/50 focus:outline-none focus:ring-primary focus:border-primary sm:text-sm"
          />
        </div>
         <div>
          <label htmlFor="guardianContact" className="block text-sm font-medium text-text-secondary">Guardian Contact (Optional)</label>
          <input
            type="text"
            id="guardianContact"
            value={guardianContact}
            onChange={(e) => setGuardianContact(e.target.value)}
             placeholder="e.g., +63 912 345 6789 or parent@email.com"
            className="mt-1 block w-full px-3 py-2 bg-background border border-border rounded-md shadow-sm placeholder-text-secondary/50 focus:outline-none focus:ring-primary focus:border-primary sm:text-sm"
          />
        </div>
      </div>
      <div className="flex justify-end pt-6 space-x-2">
        <button
          type="button"
          onClick={onCancel}
          className="px-4 py-2 bg-secondary text-text-primary font-semibold rounded-md hover:bg-secondary/80 transition-colors"
        >
          Cancel
        </button>
        <button
          type="submit"
          className="px-4 py-2 bg-primary text-white font-semibold rounded-md hover:bg-primary-dark transition-colors"
        >
          {studentToEdit ? 'Save Changes' : 'Register Student'}
        </button>
      </div>
    </form>
  );
};

export default StudentForm;
