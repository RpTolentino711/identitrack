import { NextRequest, NextResponse } from 'next/server';
import { DatabaseService } from '../../services/database';

export async function GET() {
  try {
    const students = await DatabaseService.getAllStudents();
    return NextResponse.json({ success: true, students });
  } catch (error) {
    console.error('Error fetching students:', error);
    return NextResponse.json({ success: false, error: 'Failed to fetch students' }, { status: 500 });
  }
}

export async function POST(request: NextRequest) {
  try {
    const studentData = await request.json();
    const student = await DatabaseService.createStudent(studentData);
    return NextResponse.json({ success: true, student });
  } catch (error) {
    console.error('Error creating student:', error);
    return NextResponse.json({ success: false, error: 'Failed to create student' }, { status: 500 });
  }
}
