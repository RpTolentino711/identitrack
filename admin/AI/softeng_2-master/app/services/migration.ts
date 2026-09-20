import { DatabaseService } from './database';
import { ViolationSeverity } from '../generated/prisma/enums';

// Migration utility to transfer data from localStorage to Prisma database
export class DataMigrationService {
  static async migrateFromLocalStorage() {
    try {
      console.log('Starting data migration from localStorage to Prisma...');
      
      // Check if we're in browser environment
      if (typeof window === 'undefined') {
        console.log('Not in browser environment, skipping migration');
        return;
      }

      // Migrate users
      await this.migrateUsers();
      
      // Migrate students and violations
      await this.migrateStudentsAndViolations();
      
      console.log('Data migration completed successfully');
    } catch (error) {
      console.error('Error during data migration:', error);
      throw error;
    }
  }

  private static async migrateUsers() {
    const storedUsers = localStorage.getItem('svs_users');
    if (!storedUsers) return;

    try {
      const users = JSON.parse(storedUsers);
      console.log(`Found ${users.length} users to migrate`);

      for (const user of users) {
        // Check if user already exists
        const existingUser = await DatabaseService.findUserByEmail(user.email);
        if (existingUser) {
          console.log(`User ${user.email} already exists, skipping`);
          continue;
        }

        // Create user in database
        await DatabaseService.createUser({
          username: user.email, // Using email as username for migration
          password_hash: user.password || '', // In real app, this should be hashed
          full_name: user.email.split('@')[0], // Extract name from email
          role: 'teacher' as any, // Default role
        });

        console.log(`Migrated user: ${user.email}`);
      }
    } catch (error) {
      console.error('Error migrating users:', error);
    }
  }

  private static async migrateStudentsAndViolations() {
    const storedStudents = localStorage.getItem('svs_students');
    if (!storedStudents) return;

    try {
      const students = JSON.parse(storedStudents);
      console.log(`Found ${students.length} students to migrate`);

      for (const student of students) {
        // Check if student already exists
        const existingStudent = await DatabaseService.getStudentById(student.studentId);
        if (existingStudent) {
          console.log(`Student ${student.studentId} already exists, skipping`);
          continue;
        }

        // Create student in database
        await DatabaseService.createStudent({
          student_id: student.studentId,
          name: student.name,
          grade_level: student.yearLevel,
          section: student.section,
          guardian_contact: student.guardianContact,
        });

        // Migrate violations for this student
        if (student.violations && student.violations.length > 0) {
          for (const violation of student.violations) {
            await DatabaseService.createViolation({
              student_id: student.studentId,
              description: violation.description,
              category: violation.category,
              severity: this.mapSeverity(violation.severity),
              likelihood: violation.confidence_level,
              reported_by: 'migrated_user',
            });

            console.log(`Migrated violation for student ${student.studentId}`);
          }
        }

        console.log(`Migrated student: ${student.name} (${student.studentId})`);
      }
    } catch (error) {
      console.error('Error migrating students and violations:', error);
    }
  }

  private static mapSeverity(severity: string): ViolationSeverity {
    switch (severity.toLowerCase()) {
      case 'low':
        return ViolationSeverity.Low;
      case 'medium':
        return ViolationSeverity.Medium;
      case 'high':
        return ViolationSeverity.High;
      case 'critical':
        return ViolationSeverity.Critical;
      default:
        return ViolationSeverity.Low;
    }
  }

  // Utility to clear localStorage after successful migration
  static clearLocalStorageData() {
    if (typeof window === 'undefined') return;
    
    localStorage.removeItem('svs_users');
    localStorage.removeItem('svs_students');
    sessionStorage.removeItem('svs_currentUser');
    
    console.log('Cleared localStorage data after migration');
  }

  // Check if migration is needed
  static async isMigrationNeeded(): Promise<boolean> {
    if (typeof window === 'undefined') return false;
    
    const hasLocalData = localStorage.getItem('svs_users') || localStorage.getItem('svs_students');
    if (!hasLocalData) return false;
    
    // Check if database has any data
    try {
      const students = await DatabaseService.getAllStudents();
      return students.length === 0;
    } catch (error) {
      console.error('Error checking migration status:', error);
      return false;
    }
  }
}

export default DataMigrationService;
