import 'dart:math' as math;
import 'package:flutter/material.dart';
import 'services/community_service_api.dart';
import 'dashboard_screen.dart';
import 'offense_screen.dart';
import 'alert_screen.dart';
import 'profile_screen.dart';
import 'shared_bottom_nav.dart';


DateTime _parseManilaDateTime(String dateStr) {
  if (dateStr.isEmpty) return DateTime.now();
  String cleaned = dateStr.trim();
  if (!cleaned.endsWith('Z') && !cleaned.contains(RegExp(r'[+-]\d{2}:?\d{2}$'))) {
    cleaned = '$cleaned+08:00';
  }
  try {
    return DateTime.parse(cleaned);
  } catch (_) {
    try {
      return DateTime.parse(dateStr);
    } catch (__) {
      return DateTime.now();
    }
  }
}

class ServiceHistoryScreen extends StatefulWidget {
  final String studentId;
  final String studentName;

  const ServiceHistoryScreen({
    super.key,
    required this.studentId,
    required this.studentName,
  });

  @override
  State<ServiceHistoryScreen> createState() => _ServiceHistoryScreenState();
}

class LiveSessionTimer extends StatelessWidget {
  final String timeIn;
  final double remainingHoursBeforeActive;
  const LiveSessionTimer({
    super.key,
    required this.timeIn,
    required this.remainingHoursBeforeActive,
  });

  @override
  Widget build(BuildContext context) {
    try {
      final start = _parseManilaDateTime(timeIn);
      final remainingSecondsTotal = (remainingHoursBeforeActive * 3600).round();
      return StreamBuilder(
        stream: Stream.periodic(const Duration(seconds: 1)),
        builder: (context, snapshot) {
          try {
            final elapsed = DateTime.now().difference(start).inSeconds;
            final countdownSeconds = remainingSecondsTotal - elapsed;
            
            if (countdownSeconds <= 0) {
              return const Text(
                '00:00:00',
                style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13, color: Color(0xFF2E7D32)),
              );
            }
            
            final h = (countdownSeconds ~/ 3600).toString().padLeft(2, '0');
            final m = ((countdownSeconds % 3600) ~/ 60).toString().padLeft(2, '0');
            final s = (countdownSeconds % 60).toString().padLeft(2, '0');
            return Text(
              '$h:$m:$s',
              style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13, color: Color(0xFFE65100)),
            );
          } catch (_) {
            return const Text('00:00:00');
          }
        },
      );
    } catch (_) {
      return const Text('--:--');
    }
  }
}

class _ServiceHistoryScreenState extends State<ServiceHistoryScreen> {
  final _api = CommunityServiceApi();
  bool _loading = true;
  String? _error;
  CommunityServiceOverview? _data;

  static const blue = Color(0xFF193B8C);
  static const blueDark = Color(0xFF102B6B);

  @override
  void initState() {
    super.initState();
    _load();
    Future.doWhile(() async {
      await Future.delayed(const Duration(seconds: 10));
      if (mounted) {
        await _load();
      }
      return mounted;
    });
  }

  Future<void> _load() async {
    if (_data == null) {
      setState(() {
        _loading = true;
        _error = null;
      });
    }

    try {
      final data = await _api.getOverview(widget.studentId);
      if (!mounted) return;

      setState(() {
        _data = data;
        _loading = false;
        _error = null;
      });
    } catch (e) {
      if (!mounted) return;
      if (_data == null) {
        setState(() {
          _error = e.toString().replaceFirst('Exception: ', '');
          _loading = false;
        });
      } else {
        // Log background error quietly
        debugPrint('Silent background refresh error: $e');
      }
    }
  }

  String _formatDate(String dateStr) {
    try {
      final dt = _parseManilaDateTime(dateStr);
      return '${dt.day.toString().padLeft(2, '0')}/${dt.month.toString().padLeft(2, '0')}/${dt.year} ${dt.hour.toString().padLeft(2, '0')}:${dt.minute.toString().padLeft(2, '0')}';
    } catch (_) {
      return dateStr;
    }
  }

  String _formatHoursPrecise(double hours) {
    if (hours <= 0) return '0s';
    final totalSeconds = (hours * 3600).round();
    final h = totalSeconds ~/ 3600;
    final m = (totalSeconds % 3600) ~/ 60;
    final s = totalSeconds % 60;
    
    List<String> parts = [];
    if (h > 0) parts.add('${h}h');
    if (m > 0) parts.add('${m}m');
    if (s > 0 || (h == 0 && m == 0)) parts.add('${s}s');
    return parts.join(' ');
  }

  String _calculateHoursPrecise(String timeIn, String timeOut) {
    if (timeOut.isEmpty) return '--:--';
    try {
      final start = _parseManilaDateTime(timeIn);
      final end = _parseManilaDateTime(timeOut);
      final duration = end.difference(start);
      final h = duration.inHours;
      final m = duration.inMinutes % 60;
      final s = duration.inSeconds % 60;
      
      List<String> parts = [];
      if (h > 0) parts.add('${h}h');
      if (m > 0) parts.add('${m}m');
      if (s > 0 || (h == 0 && m == 0)) parts.add('${s}s');
      return parts.join(' ');
    } catch (_) {
      return '--:--';
    }
  }

  double _getRemainingHoursBeforeActiveSession(int requirementId) {
    if (_data == null) return 0.0;
    try {
      final req = _data!.requirements.firstWhere((r) => r.requirementId == requirementId);
      final double requiredHours = req.hoursRequired;
      
      final double completedHours = _data!.sessions
          .where((s) => s.requirementId == requirementId)
          .map((s) => s.hoursDone)
          .fold(0.0, (sum, item) => sum + item);
          
      final double remaining = requiredHours - completedHours;
      return remaining < 0 ? 0.0 : remaining;
    } catch (_) {
      return 0.0;
    }
  }

  String _getRequirementName(int requirementId) {
    if (_data == null) return 'Task';
    try {
      final req = _data!.requirements.firstWhere((r) => r.requirementId == requirementId);
      return req.taskName;
    } catch (_) {
      return 'Task $requirementId';
    }
  }

  String _getRequirementLocation(int requirementId) {
    if (_data == null) return 'Unknown';
    try {
      final req = _data!.requirements.firstWhere((r) => r.requirementId == requirementId);
      return req.location;
    } catch (_) {
      return 'Unknown location';
    }
  }

  Widget _circularProgressCard() {
    // Only use server-confirmed completed hours (sessions with time_out).
    // Do NOT add live session time — that would show false completion
    // before the session is officially logged out by the admin/scanner.
    return StreamBuilder(
      stream: Stream.periodic(const Duration(seconds: 1)),
      builder: (context, snapshot) {
        try {
          if (_data == null) {
            return const SizedBox.shrink();
          }
          final double confirmedCompleted = _data!.hoursCompleted;
          final double assigned = _data!.hoursAssigned;

          double hoursRemaining = assigned - confirmedCompleted;
          if (_data!.activeSession != null) {
            try {
              final start = _parseManilaDateTime(_data!.activeSession!.timeIn);
              final elapsedHours = DateTime.now().difference(start).inSeconds / 3600.0;
              hoursRemaining -= elapsedHours;
            } catch (_) {}
          }
          if (hoursRemaining < 0) hoursRemaining = 0;

          // Only show 100% ring if the requirement is officially COMPLETED
          final bool officiallyDone = _data!.requirements.any(
            (r) => r.status.toUpperCase() == 'COMPLETED',
          );
          final double progress = assigned > 0
              ? (officiallyDone
                  ? 0.0
                  : (hoursRemaining / assigned).clamp(0.0, 1.0))
              : 0.0;

          final double liveCompleted = officiallyDone ? assigned : (assigned - hoursRemaining);

          final totalSecondsRemaining = (hoursRemaining * 3600).ceil();
          final h = (totalSecondsRemaining ~/ 3600).toString().padLeft(2, '0');
          final m = ((totalSecondsRemaining % 3600) ~/ 60).toString().padLeft(2, '0');
          final s = (totalSecondsRemaining % 60).toString().padLeft(2, '0');

          return Container(
            padding: const EdgeInsets.all(24),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(16),
              border: Border.all(color: Colors.grey.shade200),
              boxShadow: [
                BoxShadow(
                  color: Colors.black.withValues(alpha: 0.05),
                  blurRadius: 18,
                  offset: const Offset(0, 10),
                )
              ],
            ),
            child: Column(
              children: [
                SizedBox(
                  width: 160,
                  height: 160,
                  child: Stack(
                    alignment: Alignment.center,
                    children: [
                      Container(
                        decoration: BoxDecoration(
                          shape: BoxShape.circle,
                          color: Colors.grey.shade100,
                        ),
                      ),
                      SizedBox.expand(
                        child: CircularProgressIndicator(
                          value: progress,
                          strokeWidth: 12,
                          backgroundColor: Colors.grey.shade300,
                          valueColor: AlwaysStoppedAnimation<Color>(
                            officiallyDone ? const Color(0xFF2E7D32) : const Color(0xFF193B8C),
                          ),
                        ),
                      ),
                      Column(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          Text(
                            officiallyDone ? '✓' : '$h:$m:$s',
                            style: TextStyle(
                              fontSize: officiallyDone ? 40 : 28,
                              fontWeight: FontWeight.w900,
                              color: officiallyDone ? const Color(0xFF2E7D32) : const Color(0xFF193B8C),
                              letterSpacing: -0.5,
                            ),
                          ),
                          Text(
                            officiallyDone ? 'Complete!' : 'remaining',
                            style: TextStyle(
                              fontSize: 11,
                              color: Colors.grey.shade600,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 20),
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceEvenly,
                  children: [
                    Column(
                      children: [
                        Text(
                          _formatHoursPrecise(liveCompleted),
                          style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w900, color: Color(0xFF193B8C)),
                        ),
                        const SizedBox(height: 4),
                        Text(
                          'Completed',
                          style: TextStyle(fontSize: 12, color: Colors.grey.shade600),
                        ),
                      ],
                    ),
                    Container(width: 1, height: 40, color: Colors.grey.shade300),
                    Column(
                      children: [
                        Text(
                          _formatHoursPrecise(assigned),
                          style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w900, color: Color(0xFF193B8C)),
                        ),
                        const SizedBox(height: 4),
                        Text(
                          'Required',
                          style: TextStyle(fontSize: 12, color: Colors.grey.shade600),
                        ),
                      ],
                    ),
                  ],
                ),
                if (!officiallyDone && hoursRemaining <= 0) ...[
                  const SizedBox(height: 12),
                  Container(
                    width: double.infinity,
                    padding: const EdgeInsets.all(10),
                    decoration: BoxDecoration(
                      color: const Color(0xFFFFF8E1),
                      borderRadius: BorderRadius.circular(10),
                      border: Border.all(color: const Color(0xFFFFE082)),
                    ),
                    child: Text(
                      'Awaiting admin confirmation of your completed hours.',
                      textAlign: TextAlign.center,
                      style: TextStyle(
                        fontSize: 12,
                        fontWeight: FontWeight.w700,
                        color: Colors.orange.shade800,
                      ),
                    ),
                  ),
                ],
              ],
            ),
          );
        } catch (_) {
          return const SizedBox.shrink();
        }
      },
    );
  }

  Widget _sessionCard(ServiceSession session) {
    final hoursStr = _calculateHoursPrecise(session.timeIn, session.timeOut);
    final isCompleted = session.timeOut.isNotEmpty;
    final taskName = _getRequirementName(session.requirementId);
    final location = _getRequirementLocation(session.requirementId);

    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: Colors.grey.shade200),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.05),
            blurRadius: 8,
            offset: const Offset(0, 2),
          )
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                width: 36,
                height: 36,
                decoration: BoxDecoration(
                  color: isCompleted ? const Color(0xFF2E7D32).withValues(alpha: 0.12) : Colors.grey.withValues(alpha: 0.12),
                  borderRadius: BorderRadius.circular(8),
                ),
                child: Icon(
                  isCompleted ? Icons.check_circle_rounded : Icons.access_time_rounded,
                  color: isCompleted ? const Color(0xFF2E7D32) : Colors.grey,
                  size: 18,
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      taskName,
                      style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14, color: blueDark),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                    Text(
                      'Location: $location',
                      style: TextStyle(fontSize: 12, color: Colors.grey.shade600),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ],
                ),
              ),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                decoration: BoxDecoration(
                  color: isCompleted ? const Color(0xFF2E7D32).withValues(alpha: 0.12) : Colors.orange.withValues(alpha: 0.12),
                  borderRadius: BorderRadius.circular(6),
                ),
                child: Text(
                  isCompleted ? 'Completed' : 'In Progress',
                  style: TextStyle(
                    fontSize: 11,
                    fontWeight: FontWeight.w700,
                    color: isCompleted ? const Color(0xFF2E7D32) : Colors.orange.shade700,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),
          Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('Time In', style: TextStyle(fontSize: 11, color: Colors.grey.shade600)),
                    Text(
                      _formatDate(session.timeIn),
                      style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 12),
                    ),
                  ],
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('Time Out', style: TextStyle(fontSize: 11, color: Colors.grey.shade600)),
                    Text(
                      session.timeOut.isNotEmpty ? _formatDate(session.timeOut) : 'Still in session',
                      style: TextStyle(fontWeight: FontWeight.w600, fontSize: 12, color: session.timeOut.isNotEmpty ? Colors.black : Colors.grey),
                    ),
                  ],
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(isCompleted ? 'Hours' : 'Live Timer', style: TextStyle(fontSize: 11, color: Colors.grey.shade600)),
                    if (isCompleted)
                      Text(
                        hoursStr,
                        style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 12),
                      )
                    else
                      LiveSessionTimer(
                        timeIn: session.timeIn,
                        remainingHoursBeforeActive: _getRemainingHoursBeforeActiveSession(session.requirementId),
                      ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          Row(
            children: [
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                decoration: BoxDecoration(
                  color: Colors.blue.withValues(alpha: 0.12),
                  borderRadius: BorderRadius.circular(4),
                ),
                child: Text(
                  'Login: ${session.loginMethod}',
                  style: const TextStyle(fontSize: 10, fontWeight: FontWeight.w600, color: Color(0xFF193B8C)),
                ),
              ),
            ],
          ),
        ],
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
        title: const Text('Service History', style: TextStyle(fontWeight: FontWeight.w900)),
      ),
      body: SafeArea(
        child: Stack(
          children: [
            Container(
          width: double.infinity,
          decoration: const BoxDecoration(
            color: Color(0xFFF5F6FB),
            borderRadius: BorderRadius.only(topLeft: Radius.circular(28), topRight: Radius.circular(28)),
          ),
          child: _loading
              ? const Center(child: CircularProgressIndicator())
              : (_error != null)
                  ? Center(
                      child: Padding(
                        padding: const EdgeInsets.all(18),
                        child: Column(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            Text(_error!, textAlign: TextAlign.center),
                            const SizedBox(height: 10),
                            ElevatedButton(
                              onPressed: _load,
                              child: const Text('Retry'),
                            ),
                          ],
                        ),
                      ),
                    )
                  : Column(
                      children: [
                        if (_data!.isUnderInvestigation &&
                            _data!.sessions.isEmpty &&
                            _data!.activeSession == null)
                          Padding(
                            padding: const EdgeInsets.fromLTRB(18, 18, 18, 0),
                            child: Container(
                              padding: const EdgeInsets.all(14),
                              decoration: BoxDecoration(
                                color: const Color(0xFFFFF3E0),
                                borderRadius: BorderRadius.circular(12),
                                border: Border.all(color: const Color(0xFFFFCC80)),
                              ),
                              child: Row(
                                children: [
                                  const Icon(Icons.gavel_rounded, color: Color(0xFFE65100)),
                                  const SizedBox(width: 10),
                                  Expanded(
                                    child: Text(
                                      _data!.investigationMessage.isNotEmpty
                                          ? _data!.investigationMessage
                                          : 'Account restrictions are paused until the appeal is resolved.',
                                      style: TextStyle(color: Colors.orange.shade900, fontWeight: FontWeight.w700),
                                    ),
                                  ),
                                ],
                              ),
                            ),
                          )
                        else if (_data!.hasAssignment && !_data!.hasActiveAdmin && _data!.activeSession == null)
                          Padding(
                            padding: const EdgeInsets.fromLTRB(18, 18, 18, 0),
                            child: Container(
                              padding: const EdgeInsets.all(14),
                              decoration: BoxDecoration(
                                color: const Color(0xFFFFEBEE),
                                borderRadius: BorderRadius.circular(12),
                                border: Border.all(color: const Color(0xFFEF9A9A)),
                              ),
                              child: Row(
                                children: [
                                  const Icon(Icons.sensors_off_rounded, color: Color(0xFFC62828)),
                                  const SizedBox(width: 10),
                                  Expanded(
                                    child: Text(
                                      'Scanner Offline: There is no active Admin online right now. You cannot log in at the scanner.',
                                      style: TextStyle(color: Colors.red.shade900, fontWeight: FontWeight.w700),
                                    ),
                                  ),
                                ],
                              ),
                            ),
                          )
                        else if (_data!.hasAssignment && _data!.activeSession == null)
                          Padding(
                            padding: const EdgeInsets.fromLTRB(18, 18, 18, 0),
                            child: Container(
                              padding: const EdgeInsets.all(14),
                              decoration: BoxDecoration(
                                color: const Color(0xFFE8F5E9),
                                borderRadius: BorderRadius.circular(12),
                                border: Border.all(color: const Color(0xFFA5D6A7)),
                              ),
                              child: Row(
                                children: [
                                  const Icon(Icons.sensors_rounded, color: Color(0xFF2E7D32)),
                                  const SizedBox(width: 10),
                                  Expanded(
                                    child: Text(
                                      'Scanner Online: Admin is active. You can now login in the web.',
                                      style: TextStyle(color: Colors.green.shade900, fontWeight: FontWeight.w700),
                                    ),
                                  ),
                                ],
                              ),
                            ),
                          ),
                        Expanded(
                          child: !_data!.hasAssignment
                              ? Center(
                                  child: Padding(
                                    padding: const EdgeInsets.all(32),
                                    child: Column(
                                      mainAxisAlignment: MainAxisAlignment.center,
                                      children: [
                                        Icon(Icons.lock_outline_rounded, size: 72, color: Colors.grey.shade400),
                                        const SizedBox(height: 16),
                                        Text(
                                          'Service Locked',
                                          style: TextStyle(fontSize: 22, fontWeight: FontWeight.w900, color: Colors.grey.shade800),
                                        ),
                                        const SizedBox(height: 12),
                                        Text(
                                          'You do not have any active community service requirements assigned to you.',
                                          textAlign: TextAlign.center,
                                          style: TextStyle(color: Colors.grey.shade600, fontSize: 15, fontWeight: FontWeight.w500, height: 1.4),
                                        ),
                                      ],
                                    ),
                                  ),
                                )
                              : ListView(
                                  padding: const EdgeInsets.fromLTRB(18, 18, 18, 18),
                                  children: [
                                    Text(
                                      'Track your service hours',
                                      style: TextStyle(fontSize: 16, fontWeight: FontWeight.w900, color: Colors.grey.shade700),
                                    ),
                                    const SizedBox(height: 14),
                                    _circularProgressCard(),
                                    const SizedBox(height: 24),
                                    Text(
                                      'Session History',
                                      style: TextStyle(color: Colors.grey.shade700, fontWeight: FontWeight.w900),
                                    ),
                                    const SizedBox(height: 10),
                                    if (_data!.sessions.isEmpty && _data!.activeSession == null)
                                      Padding(
                                        padding: const EdgeInsets.all(28),
                                        child: Column(
                                          mainAxisAlignment: MainAxisAlignment.center,
                                          children: [
                                            Icon(Icons.history_rounded, size: 48, color: Colors.grey.shade300),
                                            const SizedBox(height: 12),
                                            Text(
                                              'No sessions recorded yet',
                                              style: TextStyle(color: Colors.grey.shade600, fontWeight: FontWeight.w600),
                                            ),
                                          ],
                                        ),
                                      )
                                    else
                                      ...[
                                        if (_data!.activeSession != null)
                                          _sessionCard(ServiceSession(
                                            sessionId: _data!.activeSession!.sessionId,
                                            requirementId: _data!.activeSession!.requirementId,
                                            timeIn: _data!.activeSession!.timeIn,
                                            timeOut: '',
                                            loginMethod: _data!.activeSession!.loginMethod,
                                            validatedBy: 0,
                                            sdoNotes: '',
                                            hoursDone: 0.0,
                                          )),
                                        ..._data!.sessions.map((s) => _sessionCard(s)),
                                      ],
                                  ],
                                ),
                        ),
                      ],
                    ),
          ),
          if (_data != null && _data!.requirements.any((r) => r.status.toUpperCase() == 'COMPLETED'))
            const IgnorePointer(
              child: ConfettiCelebration(isPlaying: true),
            ),
          ],
        ),
      ),
      bottomNavigationBar: SharedBottomNav(
        currentIndex: 2,
        studentId: widget.studentId,
        studentName: widget.studentName,
      ),
    );
  }
}

// ── CUSTOM LIGHTWEIGHT CONFETTI CELEBRATION ──
class ConfettiParticle {
  late double x;
  late double y;
  late double vx;
  late double vy;
  late double size;
  late Color color;
  late double rotation;
  late double rotationSpeed;

  ConfettiParticle(double width, double height, math.Random random) {
    x = random.nextDouble() * width;
    y = -random.nextDouble() * 200 - 20;
    vx = random.nextDouble() * 4 - 2;
    vy = random.nextDouble() * 4 + 3;
    size = random.nextDouble() * 8 + 6;
    rotation = random.nextDouble() * 2 * math.pi;
    rotationSpeed = random.nextDouble() * 0.08 - 0.04;
    
    const colors = [
      Color(0xFFFFC107), // Yellow
      Color(0xFFFF5722), // Orange/Red
      Color(0xFF4CAF50), // Green
      Color(0xFF2196F3), // Blue
      Color(0xFFE91E63), // Pink
      Color(0xFF9C27B0), // Purple
    ];
    color = colors[random.nextInt(colors.length)];
  }

  void update() {
    x += vx;
    y += vy;
    rotation += rotationSpeed;
  }
}

class ConfettiPainter extends CustomPainter {
  final List<ConfettiParticle> particles;

  ConfettiPainter({required this.particles});

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()..style = PaintingStyle.fill;

    for (final p in particles) {
      if (p.y > size.height) continue;
      
      paint.color = p.color;
      canvas.save();
      canvas.translate(p.x, p.y);
      canvas.rotate(p.rotation);
      
      canvas.drawRect(
        Rect.fromCenter(center: Offset.zero, width: p.size, height: p.size * 0.6),
        paint,
      );
      canvas.restore();
    }
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => true;
}

class ConfettiCelebration extends StatefulWidget {
  final bool isPlaying;
  const ConfettiCelebration({super.key, required this.isPlaying});

  @override
  State<ConfettiCelebration> createState() => _ConfettiCelebrationState();
}

class _ConfettiCelebrationState extends State<ConfettiCelebration>
    with SingleTickerProviderStateMixin {
  late AnimationController _controller;
  final List<ConfettiParticle> _particles = [];
  final _random = math.Random();
  bool _initialized = false;

  @override
  void initState() {
    super.initState();
    _controller = AnimationController(
      vsync: this,
      duration: const Duration(seconds: 4),
    );
    
    if (widget.isPlaying) {
      _controller.repeat();
    }
  }

  @override
  void didUpdateWidget(covariant ConfettiCelebration oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (widget.isPlaying && !_controller.isAnimating) {
      _controller.repeat();
    } else if (!widget.isPlaying && _controller.isAnimating) {
      _controller.stop();
    }
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  void _initParticles(double width, double height) {
    if (_initialized) return;
    _initialized = true;
    for (int i = 0; i < 80; i++) {
      _particles.add(ConfettiParticle(width, height, _random));
    }
  }

  @override
  Widget build(BuildContext context) {
    if (!widget.isPlaying) return const SizedBox.shrink();

    return LayoutBuilder(
      builder: (context, constraints) {
        final width = constraints.maxWidth;
        final height = constraints.maxHeight;
        
        if (width > 0 && height > 0) {
          _initParticles(width, height);
        }

        return AnimatedBuilder(
          animation: _controller,
          builder: (context, child) {
            for (final p in _particles) {
              p.update();
              if (p.y > height) {
                p.y = -_random.nextDouble() * 100 - 10;
                p.x = _random.nextDouble() * width;
                p.vy = _random.nextDouble() * 4 + 3;
              }
            }

            return CustomPaint(
              size: Size(width, height),
              painter: ConfettiPainter(particles: _particles),
            );
          },
        );
      },
    );
  }
}
