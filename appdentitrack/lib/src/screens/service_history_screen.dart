import 'package:flutter/material.dart';
import 'services/community_service_api.dart';
import 'dashboard_screen.dart';
import 'offense_screen.dart';
import 'alert_screen.dart';
import 'profile_screen.dart';
import 'shared_bottom_nav.dart';

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
  const LiveSessionTimer({super.key, required this.timeIn});

  @override
  Widget build(BuildContext context) {
    try {
      final start = DateTime.parse(timeIn);
      return StreamBuilder(
        stream: Stream.periodic(const Duration(seconds: 1)),
        builder: (context, snapshot) {
          final duration = DateTime.now().difference(start);
          if (duration.isNegative) {
            return const Text(
              '00:00:00',
              style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13, color: Color(0xFFE65100)),
            );
          }
          
          final hours = duration.inHours.toString().padLeft(2, '0');
          final minutes = (duration.inMinutes % 60).toString().padLeft(2, '0');
          final seconds = (duration.inSeconds % 60).toString().padLeft(2, '0');
          return Text(
            '$hours:$minutes:$seconds',
            style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13, color: Color(0xFFE65100)),
          );
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
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.toString().replaceFirst('Exception: ', '');
        _loading = false;
      });
    }
  }

  String _formatDate(String dateStr) {
    try {
      final dt = DateTime.parse(dateStr);
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
      final start = DateTime.parse(timeIn);
      final end = DateTime.parse(timeOut);
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
    final activeSession = _data!.activeSession;

    return StreamBuilder(
      stream: Stream.periodic(const Duration(seconds: 1)),
      builder: (context, snapshot) {
        double liveCompleted = _data!.hoursCompleted;

        if (activeSession != null) {
          try {
            final start = DateTime.parse(activeSession.timeIn);
            final duration = DateTime.now().difference(start);
            if (!duration.isNegative) {
               liveCompleted += duration.inSeconds / 3600.0;
            }
          } catch (_) {}
        }

        double hoursRemaining = _data!.hoursAssigned - liveCompleted;
        if (hoursRemaining < 0) hoursRemaining = 0;

        final progress = _data!.hoursAssigned > 0 ? liveCompleted / _data!.hoursAssigned : 0.0;

        final totalSecondsRemaining = (hoursRemaining * 3600).floor();
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
                        value: progress.clamp(0, 1),
                        strokeWidth: 12,
                        backgroundColor: Colors.grey.shade300,
                        valueColor: const AlwaysStoppedAnimation<Color>(blue),
                      ),
                    ),
                    Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Text(
                          '$h:$m:$s',
                          style: const TextStyle(
                            fontSize: 28,
                            fontWeight: FontWeight.w900,
                            color: blue,
                            letterSpacing: -0.5,
                          ),
                        ),
                        Text(
                          'countdown',
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
                        style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w900, color: blue),
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
                        _formatHoursPrecise(_data!.hoursAssigned),
                        style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w900, color: blue),
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
            ],
          ),
        );
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
                      LiveSessionTimer(timeIn: session.timeIn),
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
        backgroundColor: blue,
        foregroundColor: Colors.white,
        elevation: 0,
        title: const Text('Service History', style: TextStyle(fontWeight: FontWeight.w900)),
      ),
      body: SafeArea(
        child: Container(
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
                  : !_data!.hasAssignment
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
                                  'You do not have any community service requirements assigned to you.',
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

                        if (_data!.isUnderInvestigation)
                          Container(
                            margin: const EdgeInsets.only(bottom: 14),
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
                          )

                        else if (!_data!.hasActiveAdmin)
                          Container(
                            margin: const EdgeInsets.only(bottom: 14),
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
                          )
                        else if (_data!.activeSession == null)
                          Container(
                            margin: const EdgeInsets.only(bottom: 14),
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
      ),
      bottomNavigationBar: SharedBottomNav(
        currentIndex: 2,
        studentId: widget.studentId,
        studentName: widget.studentName,
      ),
    );
  }
}
