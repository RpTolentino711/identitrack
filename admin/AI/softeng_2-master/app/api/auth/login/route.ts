import { NextRequest, NextResponse } from 'next/server';
import { DatabaseService } from '../../../services/database';

export async function POST(request: NextRequest) {
  try {
    const { email, password } = await request.json();
    
    const user = await DatabaseService.findUserByEmail(email);
    
    if (user && user.password_hash === password) {
      const { password_hash, ...userToReturn } = user;
      return NextResponse.json({ success: true, user: userToReturn });
    }
    
    return NextResponse.json({ success: false, error: 'Invalid credentials' }, { status: 401 });
  } catch (error) {
    console.error('Login error:', error);
    return NextResponse.json({ success: false, error: 'Login failed' }, { status: 500 });
  }
}
