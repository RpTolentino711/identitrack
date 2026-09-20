import path from 'path';
import { PrismaClient } from '../generated/prisma/client';
import { ViolationSeverity, UserRole } from '../generated/prisma/enums';

// Resolve SQLite path relative to the project root
const dbPath = path.resolve(process.cwd(), 'prisma/dev.db');
const dbUrl = `file:${dbPath.replace(/\\/g, '/')}`;

// Create a singleton instance of PrismaClient
const globalForPrisma = globalThis as unknown as {
  prisma: PrismaClient | undefined;
};

export const prisma =
  globalForPrisma.prisma ??
  new PrismaClient({
    datasources: {
      db: {
        url: dbUrl,
      },
    },
  });

if (process.env.NODE_ENV !== 'production') globalForPrisma.prisma = prisma;

// Database service functions
export class DatabaseService {
  // User operations
  static async createUser(data: {
    username: string;
    password_hash: string;
    full_name: string;
    role?: UserRole;
  }) {
    return await prisma.user.create({
      data: {
        ...data,
        role: data.role || UserRole.teacher,
      },
    });
  }

  static async findUserByUsername(username: string) {
    return await prisma.user.findUnique({
      where: { username },
    });
  }

  static async findUserByEmail(email: string) {
    // Since we're migrating from email-based auth, we'll use email as username for now
    return await prisma.user.findUnique({
      where: { username: email },
    });
  }

  // Student operations
  static async createStudent(data: {
    student_id: string;
    name: string;
    grade_level: string;
    section?: string;
    guardian_contact?: string;
  }) {
    return await prisma.student.create({
      data,
    });
  }

  static async getAllStudents() {
    return await prisma.student.findMany({
      include: {
        violations: {
          orderBy: { occurred_at: 'desc' },
        },
        sanctions: true,
      },
    });
  }

  static async getStudentById(student_id: string) {
    return await prisma.student.findUnique({
      where: { student_id },
      include: {
        violations: {
          orderBy: { occurred_at: 'desc' },
        },
        sanctions: true,
      },
    });
  }

  static async updateStudent(student_id: string, data: {
    name?: string;
    grade_level?: string;
    section?: string;
    guardian_contact?: string;
  }) {
    console.log('DatabaseService: Updating student with ID:', student_id);
    console.log('DatabaseService: Update data:', data);
    
    if (!student_id) {
      throw new Error('Student ID is required for update');
    }
    
    return await prisma.student.update({
      where: { student_id },
      data: {
        ...data,
        updated_at: new Date(),
      },
    });
  }

  static async deleteStudent(student_id: string) {
    return await prisma.student.delete({
      where: { student_id },
    });
  }

  // Violation operations
  static async createViolation(data: {
    student_id: string;
    description: string;
    category: string;
    severity: ViolationSeverity;
    likelihood: number;
    reported_by?: string;
    suggested_sanction?: string;
    suggested_duration_days?: number;
  }) {
    return await prisma.violation.create({
      data: {
        ...data,
        occurred_at: new Date(),
      },
      include: {
        student: true,
      },
    });
  }

  static async getAllViolations() {
    return await prisma.violation.findMany({
      include: {
        student: true,
        sanctions: true,
      },
      orderBy: { occurred_at: 'desc' },
    });
  }

  static async getViolationsByStudent(student_id: string) {
    return await prisma.violation.findMany({
      where: { student_id },
      include: {
        sanctions: true,
      },
      orderBy: { occurred_at: 'desc' },
    });
  }

  // Sanction operations
  static async createSanction(data: {
    violation_id: number;
    student_id: string;
    sanction_type: string;
    duration_days?: number;
    notes?: string;
  }) {
    return await prisma.sanction.create({
      data: {
        ...data,
        assigned_at: new Date(),
      },
      include: {
        violation: true,
        student: true,
      },
    });
  }

  static async updateSanction(id: number, data: {
    sanction_type?: string;
    duration_days?: number;
    notes?: string;
    is_completed?: boolean;
  }) {
    return await prisma.sanction.update({
      where: { id },
      data: {
        ...data,
        updated_at: new Date(),
        completed_at: data.is_completed ? new Date() : undefined,
      },
    });
  }

  // Violation Category operations
  static async getAllViolationCategories() {
    return await prisma.violationCategory.findMany({
      orderBy: { name: 'asc' },
    });
  }

  static async createViolationCategory(data: {
    name: string;
    description?: string;
    default_severity?: ViolationSeverity;
  }) {
    return await prisma.violationCategory.create({
      data: {
        ...data,
        default_severity: data.default_severity || ViolationSeverity.Low,
      },
    });
  }

  // Statistics and analytics
  static async getViolationStats() {
    const totalViolations = await prisma.violation.count();
    const severityCounts = await prisma.violation.groupBy({
      by: ['severity'],
      _count: { severity: true },
    });
    
    const avgLikelihood = await prisma.violation.aggregate({
      _avg: { likelihood: true },
    });

    return {
      totalViolations,
      severityCounts: severityCounts.reduce((acc, item) => {
        acc[item.severity] = item._count.severity;
        return acc;
      }, {} as Record<ViolationSeverity, number>),
      avgLikelihood: Math.round(avgLikelihood._avg.likelihood || 0),
    };
  }

  static async getStudentStats() {
    const totalStudents = await prisma.student.count();
    const studentsWithViolations = await prisma.student.count({
      where: {
        violations: {
          some: {},
        },
      },
    });

    return {
      totalStudents,
      studentsWithViolations,
      studentsWithoutViolations: totalStudents - studentsWithViolations,
    };
  }
}

export default DatabaseService;
