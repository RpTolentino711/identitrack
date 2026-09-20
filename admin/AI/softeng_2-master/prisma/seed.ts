import { PrismaClient } from '../app/generated/prisma/client';
import { ViolationSeverity, UserRole } from '../app/generated/prisma/enums';

const prisma = new PrismaClient();

async function main() {
  console.log('Starting database seeding...');

  // Create a test user
  const user = await prisma.user.upsert({
    where: { username: 'admin@test.com' },
    update: {},
    create: {
      username: 'admin@test.com',
      password_hash: 'password123', // In production, hash this
      full_name: 'Admin User',
      role: UserRole.admin,
    },
  });

  console.log('Created user:', user);

  // Create violation categories
  const categories = [
    { name: 'Academic Integrity', description: 'Cheating, plagiarism, etc.', default_severity: ViolationSeverity.High },
    { name: 'Attendance', description: 'Tardiness, absences, etc.', default_severity: ViolationSeverity.Low },
    { name: 'Behavioral', description: 'Disruptive behavior, fighting, etc.', default_severity: ViolationSeverity.Medium },
    { name: 'Disciplinary', description: 'Serious violations requiring immediate action', default_severity: ViolationSeverity.Critical },
  ];

  for (const category of categories) {
    await prisma.violationCategory.upsert({
      where: { name: category.name },
      update: {},
      create: category,
    });
  }

  console.log('Created violation categories');

  // Create sample students
  const students = [
    {
      student_id: 'ST-001',
      name: 'John Doe',
      grade_level: 'Year 10',
      section: 'A',
      guardian_contact: 'parentof.johndoe@email.com',
    },
    {
      student_id: 'ST-002',
      name: 'Jane Smith',
      grade_level: 'Year 11',
      section: 'B',
      guardian_contact: '+639171234567',
    },
    {
      student_id: 'ST-003',
      name: 'Peter Jones',
      grade_level: 'Year 9',
      section: 'C',
      guardian_contact: '',
    },
  ];

  for (const student of students) {
    await prisma.student.upsert({
      where: { student_id: student.student_id },
      update: {},
      create: student,
    });
  }

  console.log('Created sample students');

  // Create sample violations
  const violations = [
    {
      student_id: 'ST-001',
      description: 'Caught cheating on a math exam.',
      category: 'Academic Integrity',
      severity: ViolationSeverity.High,
      likelihood: 85,
      reported_by: 'admin@test.com',
    },
    {
      student_id: 'ST-001',
      description: 'Repeatedly late for first period.',
      category: 'Attendance',
      severity: ViolationSeverity.Low,
      likelihood: 30,
      reported_by: 'admin@test.com',
    },
    {
      student_id: 'ST-003',
      description: 'Involved in a physical altercation in the hallway.',
      category: 'Behavioral',
      severity: ViolationSeverity.Critical,
      likelihood: 95,
      reported_by: 'admin@test.com',
    },
  ];

  for (const violation of violations) {
    await prisma.violation.create({
      data: violation,
    });
  }

  console.log('Created sample violations');

  console.log('Database seeding completed successfully!');
}

main()
  .catch((e) => {
    console.error('Error during seeding:', e);
    process.exit(1);
  })
  .finally(async () => {
    await prisma.$disconnect();
  });
