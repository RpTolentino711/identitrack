'use client';

import React, { createContext, useState, ReactNode, useEffect } from 'react';
import { ApiService } from '../services/api';
import { User, Student, Violation, CreateStudentData, UpdateStudentData, CreateViolationData } from '../types';
import { ViolationSeverity } from '../generated/prisma/enums';

interface AppContextType {
  user: User | null;
  students: Student[];
  isLoading: boolean;
  login: (email: string, pass: string) => Promise<boolean>;
  logout: () => void;
  register: (email: string, pass: string) => Promise<void>;
  addStudent: (studentData: CreateStudentData) => Promise<void>;
  updateStudent: (studentId: string, studentData: UpdateStudentData) => Promise<void>;
  deleteStudent: (studentId: string) => Promise<void>;
  addViolationToStudent: (studentId: string, violationData: Omit<CreateViolationData, 'student_id'>) => Promise<void>;
  refreshStudents: () => Promise<void>;
}

export const AppContext = createContext<AppContextType>({} as AppContextType);

interface AppProviderProps {
  children: ReactNode;
}

export const AppProvider: React.FC<AppProviderProps> = ({ children }) => {
  const [students, setStudents] = useState<Student[]>([]);
  const [user, setUser] = useState<User | null>(null);
  const [isLoading, setIsLoading] = useState(true);

  // Initialize data and handle migration
  useEffect(() => {
    const initializeApp = async () => {
      try {
        setIsLoading(true);
        
        // Check if migration is needed and perform it
        const migrationCheck = await ApiService.checkMigrationNeeded();
        if (migrationCheck.success && migrationCheck.needsMigration) {
          console.log('Performing data migration...');
          await ApiService.runMigration();
        }

        // Load students from database
        await refreshStudents();
        
        // Check for existing user session
        if (typeof window !== 'undefined') {
          const storedUser = sessionStorage.getItem('svs_currentUser');
          if (storedUser) {
            try {
              const userData = JSON.parse(storedUser);
              // For now, just set the user from session storage
              // In a real app, you'd verify with the server
              setUser(userData);
            } catch (error) {
              console.error('Error parsing stored user:', error);
              sessionStorage.removeItem('svs_currentUser');
            }
          }
        }
      } catch (error) {
        console.error('Error initializing app:', error);
      } finally {
        setIsLoading(false);
      }
    };

    initializeApp();
  }, []);

  const refreshStudents = async () => {
    try {
      const response = await ApiService.getAllStudents();
      if (response.success) {
        setStudents(response.students);
      } else {
        console.error('Error loading students:', response.error);
      }
    } catch (error) {
      console.error('Error loading students:', error);
    }
  };

  const register = async (email: string, pass: string): Promise<void> => {
    try {
      const response = await ApiService.register(email, pass);
      if (!response.success) {
        throw new Error(response.error || 'Registration failed');
      }
    } catch (error) {
      console.error('Registration error:', error);
      throw error;
    }
  };

  const login = async (email: string, pass: string): Promise<boolean> => {
    try {
      const response = await ApiService.login(email, pass);
      if (response.success) {
        setUser(response.user);
        
        if (typeof window !== 'undefined') {
          sessionStorage.setItem('svs_currentUser', JSON.stringify(response.user));
        }
        return true;
      }
      return false;
    } catch (error) {
      console.error('Login error:', error);
      return false;
    }
  };

  const logout = () => {
    setUser(null);
    if (typeof window !== 'undefined') {
      sessionStorage.removeItem('svs_currentUser');
    }
  };

  const addStudent = async (studentData: CreateStudentData): Promise<void> => {
    try {
      const response = await ApiService.createStudent(studentData);
      if (!response.success) {
        throw new Error(response.error || 'Failed to create student');
      }
      await refreshStudents();
    } catch (error) {
      console.error('Error adding student:', error);
      throw error;
    }
  };

  const updateStudent = async (studentId: string, studentData: UpdateStudentData): Promise<void> => {
    try {
      const response = await ApiService.updateStudent(studentId, studentData);
      if (!response.success) {
        throw new Error(response.error || 'Failed to update student');
      }
      await refreshStudents();
    } catch (error) {
      console.error('Error updating student:', error);
      throw error;
    }
  };

  const deleteStudent = async (studentId: string): Promise<void> => {
    try {
      const response = await ApiService.deleteStudent(studentId);
      if (!response.success) {
        throw new Error(response.error || 'Failed to delete student');
      }
      await refreshStudents();
    } catch (error) {
      console.error('Error deleting student:', error);
      throw error;
    }
  };

  const addViolationToStudent = async (
    studentId: string, 
    violationData: Omit<CreateViolationData, 'student_id'>
  ): Promise<void> => {
    try {
      const response = await ApiService.createViolation({
        ...violationData,
        student_id: studentId,
      });
      if (!response.success) {
        throw new Error(response.error || 'Failed to create violation');
      }
      await refreshStudents();
    } catch (error) {
      console.error('Error adding violation:', error);
      throw error;
    }
  };

  const value = {
    user,
    students,
    isLoading,
    login,
    logout,
    register,
    addStudent,
    updateStudent,
    deleteStudent,
    addViolationToStudent,
    refreshStudents,
  };

  return <AppContext.Provider value={value}>{children}</AppContext.Provider>;
};
