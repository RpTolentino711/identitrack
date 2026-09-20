import { NextRequest, NextResponse } from 'next/server';
import { DatabaseService } from '../../../services/database';

export async function GET(
  request: NextRequest,
  { params }: { params: Promise<{ id: string }> }
) {
  try {
    const { id } = await params;
    const student = await DatabaseService.getStudentById(id || request.nextUrl.pathname.split('/').pop() || '');
    if (!student) {
      return NextResponse.json({ success: false, error: 'Student not found' }, { status: 404 });
    }
    return NextResponse.json({ success: true, student });
  } catch (error) {
    console.error('Error fetching student:', error);
    return NextResponse.json({ success: false, error: 'Failed to fetch student' }, { status: 500 });
  }
}

export async function PUT(
  request: NextRequest,
  { params }: { params: Promise<{ id: string }> }
) {
  try {
    const updateData = await request.json();
    delete (updateData as any).student_id;

    const { id } = await params;
    const targetId = id || request.nextUrl.pathname.split('/').pop() || '';
    console.log('API Route: Updating student with ID:', targetId);
    console.log('API Route: Update data:', updateData);

    if (!targetId) {
      return NextResponse.json({ success: false, error: 'Student ID is required' }, { status: 400 });
    }

    const student = await DatabaseService.updateStudent(targetId, updateData);
    return NextResponse.json({ success: true, student });
  } catch (error: any) {
    console.error('Error updating student:', error);
    if (error?.code === 'P2025') {
      return NextResponse.json({ success: false, error: 'Student not found' }, { status: 404 });
    }
    if (typeof error?.message === 'string') {
      return NextResponse.json({ success: false, error: error.message }, { status: 400 });
    }
    return NextResponse.json({ success: false, error: 'Failed to update student' }, { status: 500 });
  }
}

export async function DELETE(
  request: NextRequest,
  { params }: { params: Promise<{ id: string }> }
) {
  try {
    const { id } = await params;
    const targetId = id || request.nextUrl.pathname.split('/').pop() || '';
    if (!targetId) {
      return NextResponse.json({ success: false, error: 'Student ID is required' }, { status: 400 });
    }
    await DatabaseService.deleteStudent(targetId);
    return NextResponse.json({ success: true });
  } catch (error) {
    console.error('Error deleting student:', error);
    return NextResponse.json({ success: false, error: 'Failed to delete student' }, { status: 500 });
  }
}
