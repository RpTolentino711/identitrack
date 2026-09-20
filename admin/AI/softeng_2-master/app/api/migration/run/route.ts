import { NextResponse } from 'next/server';
import { DataMigrationService } from '../../../services/migration';

export async function POST() {
  try {
    await DataMigrationService.migrateFromLocalStorage();
    return NextResponse.json({ success: true });
  } catch (error) {
    console.error('Error running migration:', error);
    return NextResponse.json({ success: false, error: 'Migration failed' }, { status: 500 });
  }
}
