import { NextRequest, NextResponse } from 'next/server';
import { DatabaseService } from '../../../services/database';

export async function POST(request: NextRequest) {
  try {
    const { email, password, fullName } = await request.json();
    
    // Check if user already exists
    const existingUser = await DatabaseService.findUserByEmail(email);
    if (existingUser) {
      return NextResponse.json({ success: false, error: 'User with this email already exists' }, { status: 400 });
    }

    // Create new user
    const user = await DatabaseService.createUser({
      username: email,
      password_hash: password, // In production, hash this password
      full_name: fullName || email.split('@')[0],
      role: 'teacher' as any,
    });

    const { password_hash, ...userToReturn } = user;
    return NextResponse.json({ success: true, user: userToReturn });
  } catch (error) {
    console.error('Registration error:', error);
    return NextResponse.json({ success: false, error: 'Registration failed' }, { status: 500 });
  }
}
