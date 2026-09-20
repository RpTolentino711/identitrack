// Import Prisma types
import { ViolationSeverity, UserRole } from './generated/prisma/enums';

// User interface aligned with Prisma User model
export interface User {
  id: number;
  username: string;
  full_name: string;
  role: UserRole;
  is_active: boolean;
  created_at: Date;
  updated_at?: Date;
}

// Violation prediction interface (for AI analysis)
export interface ViolationPrediction {
  category: string;
  severity: ViolationSeverity;
  confidence_level: number;
  sanction?: string;
  sanction_confidence?: number;
}

// Violation interface aligned with Prisma Violation model
export interface Violation {
  id: number;
  student_id: string;
  description: string;
  category: string;
  severity: ViolationSeverity;
  occurred_at: Date;
  reported_by?: string;
  suggested_sanction?: string;
  suggested_duration_days: number;
  is_sanction_assigned: boolean;
  created_at: Date;
  updated_at?: Date;
  likelihood: number;
  // Relations
  student?: Student;
  sanctions?: Sanction[];
}

// Student interface aligned with Prisma Student model
export interface Student {
  student_id: string;
  name: string;
  grade_level: string;
  section?: string;
  guardian_contact?: string;
  created_at: Date;
  updated_at?: Date;
  // Relations
  violations?: Violation[];
  sanctions?: Sanction[];
}

// Sanction interface aligned with Prisma Sanction model
export interface Sanction {
  id: number;
  violation_id: number;
  student_id: string;
  sanction_type: string;
  duration_days: number;
  notes?: string;
  is_completed: boolean;
  assigned_at: Date;
  completed_at?: Date;
  created_at: Date;
  updated_at?: Date;
  // Relations
  student?: Student;
  violation?: Violation;
}

// Violation Category interface aligned with Prisma ViolationCategory model
export interface ViolationCategory {
  id: number;
  name: string;
  description?: string;
  default_severity: ViolationSeverity;
  created_at: Date;
  updated_at?: Date;
}

// Legacy types for backward compatibility during migration
export type Severity = ViolationSeverity;

// Form data types for creating/updating entities
export interface CreateStudentData {
  student_id: string;
  name: string;
  grade_level: string;
  section?: string;
  guardian_contact?: string;
}

export interface UpdateStudentData {
  name?: string;
  grade_level?: string;
  section?: string;
  guardian_contact?: string;
}

export interface CreateViolationData {
  student_id: string;
  description: string;
  category: string;
  severity: ViolationSeverity;
  likelihood: number;
  reported_by?: string;
  suggested_sanction?: string;
  suggested_duration_days?: number;
}

export interface CreateSanctionData {
  violation_id: number;
  student_id: string;
  sanction_type: string;
  duration_days?: number;
  notes?: string;
}

export interface CreateUserData {
  username: string;
  password_hash: string;
  full_name: string;
  role?: UserRole;
}

// Statistics types
export interface ViolationStats {
  totalViolations: number;
  severityCounts: Record<ViolationSeverity, number>;
  avgLikelihood: number;
}

export interface StudentStats {
  totalStudents: number;
  studentsWithViolations: number;
  studentsWithoutViolations: number;
}
