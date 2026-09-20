// Client-side API service for making HTTP requests to our API routes

export class ApiService {
  private static baseUrl = '/api';

  // Auth endpoints
  static async login(email: string, password: string) {
    const response = await fetch(`${this.baseUrl}/auth/login`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email, password }),
    });
    return await response.json();
  }

  static async register(email: string, password: string, fullName?: string) {
    const response = await fetch(`${this.baseUrl}/auth/register`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email, password, fullName }),
    });
    return await response.json();
  }

  // Student endpoints
  static async getAllStudents() {
    const response = await fetch(`${this.baseUrl}/students`);
    return await response.json();
  }

  static async getStudentById(id: string) {
    const encodedId = encodeURIComponent(id);
    const response = await fetch(`${this.baseUrl}/students/${encodedId}`);
    return await response.json();
  }

  static async createStudent(studentData: any) {
    const response = await fetch(`${this.baseUrl}/students`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(studentData),
    });
    return await response.json();
  }

  static async updateStudent(id: string, studentData: any) {
    const encodedId = encodeURIComponent(id);
    const url = `${this.baseUrl}/students/${encodedId}`;
    console.log('ApiService.updateStudent URL:', url, 'payload:', studentData);
    const response = await fetch(url, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(studentData),
    });
    return await response.json();
  }

  static async deleteStudent(id: string) {
    const encodedId = encodeURIComponent(id);
    const response = await fetch(`${this.baseUrl}/students/${encodedId}`, {
      method: 'DELETE',
    });
    return await response.json();
  }

  // Violation endpoints
  static async getAllViolations() {
    const response = await fetch(`${this.baseUrl}/violations`);
    return await response.json();
  }

  static async getViolationsByStudent(studentId: string) {
    const encodedId = encodeURIComponent(studentId);
    const response = await fetch(`${this.baseUrl}/violations/student/${encodedId}`);
    return await response.json();
  }

  static async createViolation(violationData: any) {
    const response = await fetch(`${this.baseUrl}/violations`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(violationData),
    });
    return await response.json();
  }

  // Migration endpoints
  static async checkMigrationNeeded() {
    const response = await fetch(`${this.baseUrl}/migration/check`);
    return await response.json();
  }

  static async runMigration() {
    const response = await fetch(`${this.baseUrl}/migration/run`, {
      method: 'POST',
    });
    return await response.json();
  }
}
