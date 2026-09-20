import { NextRequest, NextResponse } from 'next/server';
import { DatabaseService } from '../../../../services/database';

export async function GET(
  request: NextRequest,
  { params }: { params: Promise<{ studentId: string }> }
) {
  try {
    const { studentId } = await params;
    const violations = await DatabaseService.getViolationsByStudent(studentId);
    return NextResponse.json({ success: true, violations });
  } catch (error) {
    console.error('Error fetching student violations:', error);
    return NextResponse.json({ success: false, error: 'Failed to fetch student violations' }, { status: 500 });
  }
}
