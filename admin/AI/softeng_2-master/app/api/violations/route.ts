import { NextRequest, NextResponse } from 'next/server';
import { DatabaseService } from '../../services/database';

export async function GET() {
  try {
    const violations = await DatabaseService.getAllViolations();
    return NextResponse.json({ success: true, violations });
  } catch (error) {
    console.error('Error fetching violations:', error);
    return NextResponse.json({ success: false, error: 'Failed to fetch violations' }, { status: 500 });
  }
}

export async function POST(request: NextRequest) {
  try {
    const violationData = await request.json();
    const violation = await DatabaseService.createViolation(violationData);
    return NextResponse.json({ success: true, violation });
  } catch (error) {
    console.error('Error creating violation:', error);
    return NextResponse.json({ success: false, error: 'Failed to create violation' }, { status: 500 });
  }
}
