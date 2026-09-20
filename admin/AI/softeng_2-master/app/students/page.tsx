'use client';

import React, { useState, useContext } from 'react';
import { AppContext } from '../context/AppContext';
import { Student } from '../types';
import StudentForm from '../components/StudentForm';
import PageTabs from '../components/PageTabs';
import Layout from '../components/Layout';

const StudentsPage: React.FC = () => {
    const { students, addStudent, updateStudent, deleteStudent } = useContext(AppContext);
    
    const [isFormVisible, setIsFormVisible] = useState(false);
    const [studentToEdit, setStudentToEdit] = useState<Student | null>(null);
    const [searchTerm, setSearchTerm] = useState('');

    const openFormForNew = () => {
        setStudentToEdit(null);
        setIsFormVisible(true);
    };

    const openFormForEdit = (student: Student) => {
        setStudentToEdit(student);
        setIsFormVisible(true);
    };

    const handleFormCancel = () => {
        setIsFormVisible(false);
        setStudentToEdit(null);
    };

    const handleStudentSubmit = async (studentData: Omit<Student, 'student_id' | 'violations' | 'sanctions' | 'created_at' | 'updated_at'>) => {
        try {
            if (studentToEdit) {
                console.log('StudentsPage: Updating student with ID:', studentToEdit.student_id);
                console.log('StudentsPage: Student to edit:', studentToEdit);
                console.log('StudentsPage: Student data:', studentData);
                
                if (!studentToEdit.student_id) {
                    alert('Error: Student ID is missing. Cannot update student.');
                    return;
                }
                
                await updateStudent(studentToEdit.student_id, studentData);
                alert('Student updated successfully!');
            } else {
                await addStudent({
                    student_id: `ST-${Date.now()}`, // Generate a unique student ID
                    ...studentData,
                });
                alert('Student added successfully!');
            }
            setIsFormVisible(false);
            setStudentToEdit(null);
        } catch (error) {
            console.error('Error saving student:', error);
            alert('Error saving student. Please try again.');
        }
    };
    
    const handleDelete = async (student: Student) => {
      if (window.confirm(`Are you sure you want to delete ${student.name}? This action cannot be undone.`)) {
        try {
          await deleteStudent(student.student_id);
          alert(`${student.name} has been deleted.`);
        } catch (error) {
          console.error('Error deleting student:', error);
          alert('Error deleting student. Please try again.');
        }
      }
    }
    
    const filteredStudents = students.filter(student => 
        student.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
        student.student_id.toLowerCase().includes(searchTerm.toLowerCase())
    );

    return (
        <Layout>
            <div className="space-y-6">
                <PageTabs />

            {!isFormVisible && (
                 <div className="bg-card border border-border rounded-lg p-4 flex justify-between items-center">
                    <p className="text-text-primary">Register a new student or manage existing records.</p>
                    <button onClick={openFormForNew} className="px-4 py-2 bg-primary text-white font-semibold rounded-md hover:bg-primary-dark transition-colors">
                        Register Student
                    </button>
                </div>
            )}
           
            {isFormVisible && (
              <div className="bg-card rounded-lg border border-border animate-fade-in">
                <div className="p-4 border-b border-border">
                  <h3 className="text-lg font-semibold text-text-primary">{studentToEdit ? 'Edit Student Information' : 'Register New Student'}</h3>
                </div>
                <StudentForm 
                  onSubmit={handleStudentSubmit} 
                  studentToEdit={studentToEdit}
                  onCancel={handleFormCancel}
                />
              </div>
            )}

            <div className="bg-card border border-border rounded-lg">
                <div className="p-4 border-b border-border flex justify-between items-center">
                    <div>
                        <h3 className="text-lg font-semibold text-text-primary">Registered Students ({students.length} total)</h3>
                    </div>
                     <div className="relative w-full max-w-xs">
                         <input
                            type="text"
                            placeholder="Search students..."
                            value={searchTerm}
                            onChange={(e) => setSearchTerm(e.target.value)}
                            className="w-full pl-10 pr-4 py-2 bg-background border border-border rounded-lg focus:outline-none focus:ring-1 focus:ring-primary"
                        />
                        <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <svg className="w-5 h-5 text-text-secondary" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                               <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                        </div>
                    </div>
                </div>

                <div className="overflow-x-auto">
                    <table className="min-w-full divide-y divide-border">
                        <thead className="bg-secondary/50">
                            <tr>
                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-text-secondary uppercase tracking-wider">Student Info</th>
                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-text-secondary uppercase tracking-wider">Year & Section</th>
                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-text-secondary uppercase tracking-wider">Guardian Contact</th>
                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-text-secondary uppercase tracking-wider">Violations</th>
                                <th scope="col" className="px-6 py-3 text-right text-xs font-medium text-text-secondary uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="bg-card divide-y divide-border">
                           {filteredStudents.length > 0 ? (
                                filteredStudents.map(student => (
                                    <tr key={student.student_id}>
                                        <td className="px-6 py-4 whitespace-nowrap">
                                            <div className="font-medium text-text-primary">{student.name}</div>
                                            <div className="text-sm text-text-secondary">ID: {student.student_id}</div>
                                        </td>
                                        <td className="px-6 py-4 whitespace-nowrap text-sm text-text-secondary">
                                            <div>{student.grade_level}</div>
                                            <div>{student.section || 'N/A'}</div>
                                        </td>
                                        <td className="px-6 py-4 whitespace-nowrap text-sm text-text-secondary">
                                            {student.guardian_contact || <span className="italic">Not provided</span>}
                                        </td>
                                        <td className="px-6 py-4 whitespace-nowrap text-sm text-text-primary">
                                            <span className="font-semibold bg-secondary/80 px-3 py-1 rounded-full">{(student.violations || []).length} Total</span>
                                        </td>
                                        <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                            <div className="flex justify-end space-x-2">
                                                <button onClick={() => openFormForEdit(student)} className="text-yellow-400 hover:text-yellow-300 transition-colors p-1" title="Edit Student">
                                                    <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M17.414 2.586a2 2 0 00-2.828 0L7 10.172V13h2.828l7.586-7.586a2 2 0 000-2.828z" /><path fillRule="evenodd" d="M2 6a2 2 0 012-2h4a1 1 0 010 2H4v10h10v-4a1 1 0 112 0v4a2 2 0 01-2 2H4a2 2 0 01-2-2V6z" clipRule="evenodd" /></svg>
                                                </button>
                                                <button onClick={() => handleDelete(student)} className="text-red-500 hover:text-red-400 transition-colors p-1" title="Delete Student">
                                                    <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fillRule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm4 0a1 1 0 012 0v6a1 1 0 11-2 0V8z" clipRule="evenodd" /></svg>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                ))
                             ) : (
                                <tr>
                                    <td colSpan={5} className="text-center py-10">
                                        <p className="text-text-secondary">No students found.</p>
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
            </div>
        </Layout>
    );
};

export default StudentsPage;
