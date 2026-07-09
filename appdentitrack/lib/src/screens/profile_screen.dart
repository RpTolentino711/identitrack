import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'login_screen.dart';
import 'shared_bottom_nav.dart';
import 'services/profile_api.dart';

class ProfileScreen extends StatefulWidget {
  final String studentId;
  final String studentName;

  const ProfileScreen({
    super.key,
    required this.studentId,
    required this.studentName,
  });

  @override
  State<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends State<ProfileScreen> {
  bool _loggingOut = false;
  bool _isLoading = true;
  bool _isSaving = false;
  String? _errorMessage;

  final _formKey = GlobalKey<FormState>();

  final _phoneController = TextEditingController();
  final _gfnController = TextEditingController();
  final _glnController = TextEditingController();
  final _gemailController = TextEditingController();
  final _gphoneController = TextEditingController();

  static const blue = Color(0xFF193B8C);
  static const blueDark = Color(0xFF102B6B);
  static const redError = Color(0xFFC62828);

  @override
  void initState() {
    super.initState();
    _fetchProfile();
  }

  @override
  void dispose() {
    _phoneController.dispose();
    _gfnController.dispose();
    _glnController.dispose();
    _gemailController.dispose();
    _gphoneController.dispose();
    super.dispose();
  }

  Future<void> _fetchProfile() async {
    setState(() {
      _isLoading = true;
      _errorMessage = null;
    });

    try {
      final profile = await ProfileApi().getProfile(widget.studentId);
      _phoneController.text = profile.phoneNumber;
      _gfnController.text = profile.guardianFn;
      _glnController.text = profile.guardianLn;
      _gemailController.text = profile.guardianEmail;
      _gphoneController.text = profile.guardianNumber;
    } catch (e) {
      _errorMessage = e.toString();
    } finally {
      if (mounted) {
        setState(() {
          _isLoading = false;
        });
      }
    }
  }

  Future<void> _saveProfile() async {
    if (!_formKey.currentState!.validate()) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Please correct the validation errors.'),
          backgroundColor: redError,
        ),
      );
      return;
    }

    setState(() {
      _isSaving = true;
    });

    try {
      await ProfileApi().updateProfile(
        studentId: widget.studentId,
        phoneNumber: _phoneController.text.trim(),
        guardianFn: _gfnController.text.trim(),
        guardianLn: _glnController.text.trim(),
        guardianEmail: _gemailController.text.trim(),
        guardianNumber: _gphoneController.text.trim(),
      );
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Profile updated successfully!'),
          backgroundColor: Colors.green,
        ),
      );
      _fetchProfile();
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text('Failed to update profile: $e'),
          backgroundColor: redError,
        ),
      );
    } finally {
      if (mounted) {
        setState(() {
          _isSaving = false;
        });
      }
    }
  }

  String _getInitials(String name) {
    final parts = name.trim().split(' ');
    if (parts.isEmpty) return '?';
    if (parts.length == 1) return parts[0][0].toUpperCase();
    return '${parts[0][0]}${parts[parts.length - 1][0]}'.toUpperCase();
  }

  Future<void> _logout() async {
    setState(() => _loggingOut = true);

    showDialog(
      context: context,
      barrierDismissible: false,
      builder: (context) => const Center(
        child: CircularProgressIndicator(color: Colors.white),
      ),
    );

    try {
      final prefs = await SharedPreferences.getInstance();
      await prefs.remove('student_id');
      await prefs.remove('student_name');
      await prefs.remove('otp_token');

      const secureStorage = FlutterSecureStorage();
      await secureStorage.delete(key: 'otp_token');

      await Future.delayed(const Duration(milliseconds: 600));

      if (!mounted) return;

      Navigator.of(context).pushAndRemoveUntil(
        MaterialPageRoute(builder: (_) => const LoginScreen()),
        (route) => false,
      );
    } catch (e) {
      if (!mounted) return;
      Navigator.of(context).pop();
      setState(() => _loggingOut = false);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Error logging out: $e')),
      );
    }
  }

  Widget _readOnlyRow({
    required IconData icon,
    required String label,
    required String value,
  }) {
    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.grey.shade100,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: Colors.grey.shade300),
      ),
      child: Row(
        children: [
          Container(
            width: 44,
            height: 44,
            decoration: BoxDecoration(
              color: blue.withOpacity(0.12),
              borderRadius: BorderRadius.circular(10),
            ),
            child: Icon(icon, color: blue),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  label,
                  style: TextStyle(color: Colors.grey.shade700, fontWeight: FontWeight.w600, fontSize: 12),
                ),
                const SizedBox(height: 4),
                Text(
                  value,
                  style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14, color: blueDark),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildTextField({
    required TextEditingController controller,
    required String label,
    required IconData icon,
    required String? Function(String?) validator,
    TextInputType keyboardType = TextInputType.text,
  }) {
    final bool isEmpty = controller.text.trim().isEmpty;
    return Container(
      margin: const EdgeInsets.only(bottom: 16),
      child: TextFormField(
        controller: controller,
        keyboardType: keyboardType,
        onChanged: (_) {
          setState(() {});
        },
        validator: validator,
        style: const TextStyle(fontWeight: FontWeight.w600, color: blueDark, fontSize: 14),
        decoration: InputDecoration(
          labelText: label,
          prefixIcon: Icon(icon, color: isEmpty ? redError : blue),
          labelStyle: TextStyle(
            color: isEmpty ? redError : Colors.grey.shade600,
            fontWeight: FontWeight.w600,
            fontSize: 12,
          ),
          filled: true,
          fillColor: Colors.white,
          contentPadding: const EdgeInsets.symmetric(vertical: 16, horizontal: 16),
          enabledBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(12),
            borderSide: BorderSide(
              color: isEmpty ? redError.withOpacity(0.6) : Colors.grey.shade300,
              width: isEmpty ? 1.8 : 1.0,
            ),
          ),
          focusedBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(12),
            borderSide: BorderSide(
              color: isEmpty ? redError : blue,
              width: 2.0,
            ),
          ),
          errorBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(12),
            borderSide: const BorderSide(
              color: redError,
              width: 1.8,
            ),
          ),
          focusedErrorBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(12),
            borderSide: const BorderSide(
              color: redError,
              width: 2.0,
            ),
          ),
          errorStyle: const TextStyle(color: redError, fontWeight: FontWeight.w700),
          helperText: isEmpty ? 'Required field (not set)' : null,
          helperStyle: const TextStyle(color: redError, fontWeight: FontWeight.w700, fontSize: 11),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: blue,
      appBar: AppBar(
        automaticallyImplyLeading: false,
        backgroundColor: blue,
        foregroundColor: Colors.white,
        elevation: 0,
        title: const Text('Profile', style: TextStyle(fontWeight: FontWeight.w900)),
      ),
      body: SafeArea(
        child: Container(
          width: double.infinity,
          height: double.infinity,
          decoration: const BoxDecoration(
            color: Color(0xFFF5F6FB),
            borderRadius: BorderRadius.only(topLeft: Radius.circular(28), topRight: Radius.circular(28)),
          ),
          child: _isLoading
              ? const Center(child: CircularProgressIndicator(color: blue))
              : _errorMessage != null
                  ? Center(
                      child: Padding(
                        padding: const EdgeInsets.all(20.0),
                        child: Column(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            const Icon(Icons.error_outline_rounded, size: 64, color: redError),
                            const SizedBox(height: 16),
                            Text(
                              'Failed to load profile details:\n$_errorMessage',
                              textAlign: TextAlign.center,
                              style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w700, color: blueDark),
                            ),
                            const SizedBox(height: 16),
                            ElevatedButton.icon(
                              onPressed: _fetchProfile,
                              icon: const Icon(Icons.refresh_rounded),
                              label: const Text('Retry'),
                              style: ElevatedButton.styleFrom(backgroundColor: blue, foregroundColor: Colors.white),
                            ),
                          ],
                        ),
                      ),
                    )
                  : Form(
                      key: _formKey,
                      child: ListView(
                        padding: const EdgeInsets.fromLTRB(18, 22, 18, 22),
                        children: [
                          // Profile Avatar Section
                          Container(
                            padding: const EdgeInsets.all(20),
                            decoration: BoxDecoration(
                              color: Colors.white,
                              borderRadius: BorderRadius.circular(16),
                              border: Border.all(color: Colors.grey.shade200),
                              boxShadow: [
                                BoxShadow(
                                  color: Colors.black.withOpacity(0.05),
                                  blurRadius: 18,
                                  offset: const Offset(0, 10),
                                )
                              ],
                            ),
                            child: Column(
                              children: [
                                Container(
                                  width: 80,
                                  height: 80,
                                  decoration: BoxDecoration(
                                    color: blue,
                                    borderRadius: BorderRadius.circular(40),
                                  ),
                                  child: Center(
                                    child: Text(
                                      _getInitials(widget.studentName),
                                      style: const TextStyle(
                                        fontSize: 32,
                                        fontWeight: FontWeight.w900,
                                        color: Colors.white,
                                      ),
                                    ),
                                  ),
                                ),
                                const SizedBox(height: 16),
                                Text(
                                  widget.studentName,
                                  style: const TextStyle(
                                    fontSize: 20,
                                    fontWeight: FontWeight.w900,
                                    color: blueDark,
                                  ),
                                  textAlign: TextAlign.center,
                                  maxLines: 2,
                                  overflow: TextOverflow.ellipsis,
                                ),
                                const SizedBox(height: 4),
                                Text(
                                  'Student ID: ${widget.studentId}',
                                  style: TextStyle(
                                    fontSize: 12,
                                    color: Colors.grey.shade600,
                                    fontWeight: FontWeight.w600,
                                  ),
                                ),
                              ],
                            ),
                          ),
                          const SizedBox(height: 24),

                          // Read-Only Section
                          Text(
                            'Administrative Information',
                            style: TextStyle(
                              color: Colors.grey.shade700,
                              fontWeight: FontWeight.w900,
                              fontSize: 14,
                            ),
                          ),
                          const SizedBox(height: 12),
                          _readOnlyRow(
                            icon: Icons.badge_rounded,
                            label: 'Student ID',
                            value: widget.studentId,
                          ),
                          _readOnlyRow(
                            icon: Icons.person_rounded,
                            label: 'Full Name',
                            value: widget.studentName,
                          ),

                          const SizedBox(height: 20),

                          // Student Contact Info
                          Text(
                            'Student Contact Information',
                            style: TextStyle(
                              color: Colors.grey.shade700,
                              fontWeight: FontWeight.w900,
                              fontSize: 14,
                            ),
                          ),
                          const SizedBox(height: 12),
                          _buildTextField(
                            controller: _phoneController,
                            label: 'Student Phone Number',
                            icon: Icons.phone_android_rounded,
                            keyboardType: TextInputType.phone,
                            validator: (val) {
                              if (val == null || val.trim().isEmpty) {
                                return 'Phone number is required';
                              }
                              final regex = RegExp(r'^\+?[0-9]{7,15}$');
                              if (!regex.hasMatch(val.trim())) {
                                return 'Invalid phone number format';
                              }
                              return null;
                            },
                          ),

                          const SizedBox(height: 20),

                          // Guardian Contact Info
                          Text(
                            'Guardian Information',
                            style: TextStyle(
                              color: Colors.grey.shade700,
                              fontWeight: FontWeight.w900,
                              fontSize: 14,
                            ),
                          ),
                          const SizedBox(height: 12),
                          _buildTextField(
                            controller: _gfnController,
                            label: 'Guardian First Name',
                            icon: Icons.person_outline_rounded,
                            validator: (val) {
                              if (val == null || val.trim().isEmpty) {
                                return 'Guardian first name is required';
                              }
                              return null;
                            },
                          ),
                          _buildTextField(
                            controller: _glnController,
                            label: 'Guardian Last Name',
                            icon: Icons.person_outline_rounded,
                            validator: (val) {
                              if (val == null || val.trim().isEmpty) {
                                return 'Guardian last name is required';
                              }
                              return null;
                            },
                          ),
                          _buildTextField(
                            controller: _gemailController,
                            label: 'Guardian Email Address',
                            icon: Icons.email_outlined,
                            keyboardType: TextInputType.emailAddress,
                            validator: (val) {
                              if (val == null || val.trim().isEmpty) {
                                return 'Guardian email address is required';
                              }
                              final regex = RegExp(r'^[\w-\.]+@([\w-]+\.)+[\w-]{2,4}$');
                              if (!regex.hasMatch(val.trim())) {
                                return 'Invalid email address format';
                              }
                              return null;
                            },
                          ),
                          _buildTextField(
                            controller: _gphoneController,
                            label: 'Guardian Contact Number',
                            icon: Icons.phone_rounded,
                            keyboardType: TextInputType.phone,
                            validator: (val) {
                              if (val == null || val.trim().isEmpty) {
                                return 'Guardian phone number is required';
                              }
                              final regex = RegExp(r'^\+?[0-9]{7,15}$');
                              if (!regex.hasMatch(val.trim())) {
                                return 'Invalid phone number format';
                              }
                              return null;
                            },
                          ),

                          const SizedBox(height: 24),

                          // Action Buttons
                          SizedBox(
                            height: 50,
                            child: ElevatedButton.icon(
                              onPressed: _isSaving ? null : _saveProfile,
                              icon: _isSaving
                                  ? const SizedBox(
                                      width: 20,
                                      height: 20,
                                      child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                                    )
                                  : const Icon(Icons.save_rounded),
                              label: Text(_isSaving ? 'Saving changes...' : 'Save Changes'),
                              style: ElevatedButton.styleFrom(
                                backgroundColor: const Color(0xFF2E7D32),
                                foregroundColor: Colors.white,
                                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                              ),
                            ),
                          ),

                          const SizedBox(height: 12),

                          // Logout Button
                          SizedBox(
                            height: 50,
                            child: ElevatedButton.icon(
                              onPressed: _loggingOut ? null : _logout,
                              icon: _loggingOut
                                  ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2))
                                  : const Icon(Icons.logout_rounded),
                              label: Text(_loggingOut ? 'Logging out...' : 'Logout'),
                              style: ElevatedButton.styleFrom(
                                backgroundColor: const Color(0xFFC62828),
                                foregroundColor: Colors.white,
                                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                              ),
                            ),
                          ),

                          const SizedBox(height: 14),
                          Text(
                            'You will be returned to the login screen upon logout.',
                            style: TextStyle(fontSize: 12, color: Colors.grey.shade600, fontStyle: FontStyle.italic),
                            textAlign: TextAlign.center,
                          ),

                          const SizedBox(height: 24),

                          // Footer Info
                          Container(
                            padding: const EdgeInsets.all(14),
                            decoration: BoxDecoration(
                              color: Colors.blue.shade50,
                              borderRadius: BorderRadius.circular(12),
                              border: Border.all(color: Colors.blue.shade200),
                            ),
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                const Text(
                                  'IdentiTrack v1.0.2',
                                  style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13, color: blueDark),
                                ),
                                const SizedBox(height: 4),
                                Text(
                                  'Student Discipline Management System',
                                  style: TextStyle(fontSize: 12, color: Colors.grey.shade700),
                                ),
                              ],
                            ),
                          ),
                        ],
                      ),
                    ),
        ),
      ),
      bottomNavigationBar: SharedBottomNav(
        currentIndex: 4,
        studentId: widget.studentId,
        studentName: widget.studentName,
      ),
    );
  }
}
