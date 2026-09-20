import { NextResponse } from 'next/server';
import { DataMigrationService } from '../../../services/migration';

export async function GET() {
  try {
    const needsMigration = await DataMigrationService.isMigrationNeeded();
    return NextResponse.json({ success: true, needsMigration });
  } catch (error) {
    console.error('Error checking migration status:', error);
    return NextResponse.json({ success: false, error: 'Failed to check migration status' }, { status: 500 });
  }
}
