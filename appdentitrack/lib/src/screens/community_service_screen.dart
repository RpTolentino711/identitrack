import 'dart:async';

import 'package:flutter/material.dart';

import 'services/community_service_api.dart';

class CommunityServiceScreen extends StatefulWidget {
  final String studentId;
  final String studentName;

  const CommunityServiceScreen({
    super.key,
    required this.studentId,
    required this.studentName,
  });

  @override
  State<CommunityServiceScreen> createState() => _CommunityServiceScreenState();
}

class _CommunityServiceScreenState extends State<CommunityServiceScreen> {
  final _api = CommunityServiceApi();

  bool _loading = true;
  String? _error;

  double _hoursAssigned = 0;
  double _hoursCompleted = 0;
  bool _hasAssignment = false;

  List<ServiceRequirement> _requirements = [];
  List<ServiceSession> _sessions = [];
  ActiveServiceSession? _activeSession;
  PendingManualRequest? _pendingManualRequest;

  Timer? _ticker;
  Duration _elapsed = Duration.zero;

  static const blue = Color(0xFF193B8C);
  static const blueDark = Color(0xFF102B6B);

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _ticker?.cancel();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });

    try {
      final res = await _api.getOverview(widget.studentId);
      if (!mounted) return;

      setState(() {
        _hoursAssigned = res.hoursAssigned;
        _hoursCompleted = res.hoursCompleted;
        _hasAssignment = res.hasAssignment;
        _requirements = res.requirements;
        _sessions = res.sessions;
        _activeSession = res.activeSession;
        _pendingManualRequest = res.pendingManualRequest;
        _loading = false;
      });

      _syncTimer();
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.toString().replaceFirst('Exception: ', '');
        _loading = false;
      });
    }
  }

  DateTime? _parseServerDateTime(String value) {
    final raw = value.trim();
    if (raw.isEmpty) return null;
    return DateTime.tryParse(raw.replaceFirst(' ', 'T'));
  }

  void _syncTimer() {
    _ticker?.cancel();

    final s = _activeSession;
    if (s == null) {
      if (mounted) setState(() => _elapsed = Duration.zero);
      return;
    }

    final start = _parseServerDateTime(s.timeIn);
    if (start == null) {
      if (mounted) setState(() => _elapsed = Duration.zero);
      return;
    }

    void tick() {
      final diff = DateTime.now().difference(start);
      if (!mounted) return;
      setState(() {
        _elapsed = diff.isNegative ? Duration.zero : diff;
      });
    }

    tick();
    _ticker = Timer.periodic(const Duration(seconds: 1), (_) => tick());
  }

  String _durationText(Duration d) {
    final h = d.inHours;
    final m = d.inMinutes.remainder(60);
    final s = d.inSeconds.remainder(60);
    String two(int v) => v.toString().padLeft(2, '0');
    return '${two(h)}:${two(m)}:${two(s)}';
  }

  Widget _circularProgressCard() {
    final hoursRemaining = _hoursAssigned - _hoursCompleted;
    final progress = _hoursAssigned > 0 ? _hoursCompleted / _hoursAssigned : 0.0;

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
            width: 140,
            height: 140,
            child: Stack(
              alignment: Alignment.center,
              children: [
                // Background circle
                Container(
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    color: Colors.grey.shade100,
                  ),
                ),
                // Progress circle
                SizedBox.expand(
                  child: CircularProgressIndicator(
                    value: progress.clamp(0, 1),
                    strokeWidth: 12,
                    backgroundColor: Colors.grey.shade300,
                    valueColor: const AlwaysStoppedAnimation<Color>(blue),
                  ),
                ),
                // Center text
                Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    Text(
                      hoursRemaining.toStringAsFixed(1),
                      style: const TextStyle(
                        fontSize: 32,
                        fontWeight: FontWeight.w900,
                        color: blue,
                      ),
                    ),
                    Text(
                      'hours remaining',
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
                    _hoursCompleted.toStringAsFixed(1),
                    style: const TextStyle(
                      fontSize: 18,
                      fontWeight: FontWeight.w900,
                      color: blue,
                    ),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    'Completed',
                    style: TextStyle(
                      fontSize: 12,
                      color: Colors.grey.shade600,
                    ),
                  ),
                ],
              ),
              Container(
                width: 1,
                height: 40,
                color: Colors.grey.shade300,
              ),
              Column(
                children: [
                  Text(
                    _hoursAssigned.toStringAsFixed(0),
                    style: const TextStyle(
                      fontSize: 18,
                      fontWeight: FontWeight.w900,
                      color: blue,
                    ),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    'Required',
                    style: TextStyle(
                      fontSize: 12,
                      color: Colors.grey.shade600,
                    ),
                  ),
                ],
              ),
            ],
          ),
        ],
      ),
    );
  }

  ServiceRequirement? _selectedRequirement() {
    if (_requirements.isEmpty) return null;
    for (final r in _requirements) {
      if (r.status.toUpperCase() == 'ACTIVE') return r;
    }
    return _requirements.first;
  }

  Future<void> _requestManualLogin() async {
    final req = _selectedRequirement();
    if (req == null || req.requirementId <= 0 || !_hasAssignment) {
      _snack('You have no active Category 2 assignment yet.');
      return;
    }

    if (_activeSession != null) {
      _snack('Your service timer is already running.');
      return;
    }

    if (_pendingManualRequest != null) {
      _snack('A manual login request is already pending admin approval.');
      return;
    }

    final reasonCtrl = TextEditingController();
    final reason = await showDialog<String>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Request Manual Login'),
        content: TextField(
          controller: reasonCtrl,
          minLines: 2,
          maxLines: 4,
          decoration: const InputDecoration(hintText: 'Optional note for Admin...'),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(),
            child: const Text('Cancel'),
          ),
          ElevatedButton(
            onPressed: () => Navigator.of(context).pop(reasonCtrl.text.trim()),
            child: const Text('Submit'),
          ),
        ],
      ),
    );

    if (reason == null) return;

    setState(() => _loading = true);
    try {
      final res = await _api.requestManualLogin(
        studentId: widget.studentId,
        requirementId: req.requirementId,
        reason: reason,
      );
      if (!mounted) return;
      _snack(res.message.isEmpty ? 'Manual login request submitted.' : res.message);
      await _load();
    } catch (e) {
      if (!mounted) return;
      setState(() => _loading = false);
      _snack(e.toString().replaceFirst('Exception: ', ''));
    }
  }

  void _snack(String msg) {
    ScaffoldMessenger.of(context)
      ..clearSnackBars()
      ..showSnackBar(SnackBar(content: Text(msg)));
  }

  Widget _statCard({required String title, required String value, IconData? icon, Color? color}) {
    return Container(
      padding: const EdgeInsets.all(16),
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
      child: Row(
        children: [
          if (icon != null)
            Container(
              width: 44,
              height: 44,
              decoration: BoxDecoration(
                color: (color ?? blueDark).withValues(alpha: 0.12),
                borderRadius: BorderRadius.circular(12),
              ),
              child: Icon(icon, color: color ?? blueDark),
            ),
          if (icon != null) const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(title, style: TextStyle(color: Colors.grey.shade700, fontWeight: FontWeight.w700)),
                const SizedBox(height: 6),
                Text(value, style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w900, color: blueDark)),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _requirementTile(ServiceRequirement req) {
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: Colors.grey.shade200),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(req.taskName, style: const TextStyle(fontWeight: FontWeight.w900, color: blueDark)),
          const SizedBox(height: 2),
          Text('Location: ${req.location}', style: TextStyle(color: Colors.grey.shade700)),
          const SizedBox(height: 2),
          Text('Status: ${req.status}', style: TextStyle(color: Colors.grey.shade700)),
          const SizedBox(height: 2),
          Text('Hours required: ${req.hoursRequired.toStringAsFixed(2)}', style: TextStyle(color: Colors.grey.shade700)),
          const SizedBox(height: 2),
          Text('Assigned at: ${req.assignedAt}', style: TextStyle(color: Colors.grey.shade700)),
        ],
      ),
    );
  }

  Widget _sessionTile(ServiceSession s) {
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: Colors.grey.shade200),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('Session #${s.sessionId}', style: const TextStyle(fontWeight: FontWeight.w900, color: blueDark)),
          const SizedBox(height: 2),
          Text('Time In: ${s.timeIn}', style: TextStyle(color: Colors.grey.shade700)),
          Text('Time Out: ${s.timeOut.isEmpty ? '-' : s.timeOut}', style: TextStyle(color: Colors.grey.shade700)),
          Text('Hours: ${s.hoursDone.toStringAsFixed(2)}', style: TextStyle(color: Colors.grey.shade700)),
          Text('Login Method: ${s.loginMethod}', style: TextStyle(color: Colors.grey.shade700)),
          if (s.sdoNotes.isNotEmpty)
            Text('SDO Notes: ${s.sdoNotes}', style: TextStyle(color: Colors.orange.shade700)),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final actionLabel = !_hasAssignment
        ? 'No Assignment Yet'
        : _activeSession != null
            ? 'Timer Running (Check In Office)'
            : _pendingManualRequest != null
                ? 'Manual Login Pending Approval'
                : 'Request Manual Login';

    return Scaffold(
      backgroundColor: blue,
      appBar: AppBar(
        backgroundColor: blue,
        foregroundColor: Colors.white,
        elevation: 0,
        title: const Text('Community Service', style: TextStyle(fontWeight: FontWeight.w900)),
        actions: [
          IconButton(
            tooltip: 'Refresh',
            onPressed: _loading ? null : _load,
            icon: const Icon(Icons.refresh_rounded),
          ),
        ],
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
                            ElevatedButton(onPressed: _load, child: const Text('Retry')),
                          ],
                        ),
                      ),
                    )
                  : ListView(
                      padding: const EdgeInsets.fromLTRB(18, 22, 18, 22),
                      children: [
                        Text(
                          'Track your service hours',
                          style: TextStyle(fontSize: 16, fontWeight: FontWeight.w900, color: Colors.grey.shade700),
                        ),
                        const SizedBox(height: 14),
                        _circularProgressCard(),
                        const SizedBox(height: 24),
                        Text(
                          'Service Timer',
                          style: TextStyle(fontSize: 14, fontWeight: FontWeight.w900, color: Colors.grey.shade700),
                        ),
                        const SizedBox(height: 10),
                        _statCard(
                          title: _activeSession != null ? 'Service timer (running)' : 'Service timer (frozen)',
                          value: _activeSession != null ? _durationText(_elapsed) : '00:00:00',
                          icon: Icons.timer_outlined,
                          color: _activeSession != null ? const Color(0xFF2E7D32) : Colors.grey,
                        ),
                        if (_pendingManualRequest != null) ...[
                          const SizedBox(height: 10),
                          Container(
                            padding: const EdgeInsets.all(12),
                            decoration: BoxDecoration(
                              color: const Color(0xFFFFF3E0),
                              borderRadius: BorderRadius.circular(12),
                              border: Border.all(color: const Color(0xFFFFCC80)),
                            ),
                            child: Text(
                              'Manual login pending admin approval since ${_pendingManualRequest!.requestedAt}.',
                              style: const TextStyle(fontWeight: FontWeight.w700, color: Color(0xFF5D4037)),
                            ),
                          ),
                        ],
                        const SizedBox(height: 24),
                        ElevatedButton.icon(
                          icon: const Icon(Icons.login_rounded),
                          label: Text(actionLabel),
                          style: ElevatedButton.styleFrom(
                            backgroundColor: blueDark,
                            foregroundColor: Colors.white,
                            padding: const EdgeInsets.symmetric(vertical: 16),
                            textStyle: const TextStyle(fontWeight: FontWeight.w900),
                          ),
                          onPressed: (!_hasAssignment || _activeSession != null || _pendingManualRequest != null || _loading)
                              ? null
                              : _requestManualLogin,
                        ),
                        const SizedBox(height: 8),
                        Text(
                          !_hasAssignment
                              ? 'You can view this page, but actions are disabled until UPCC/Admin assigns Category 2 service.'
                              : _activeSession != null
                                  ? 'Timer runs only after successful office validation (manual/scanner).'
                                  : 'Your timer remains frozen until manual request is approved or office scanner login succeeds.',
                          style: TextStyle(color: Colors.grey.shade700, fontWeight: FontWeight.w600),
                        ),
                        const SizedBox(height: 24),
                        const Text('Assigned Tasks', style: TextStyle(fontWeight: FontWeight.w900, color: blueDark)),
                        const SizedBox(height: 8),
                        if (_requirements.isEmpty)
                          Text('No assigned tasks.', style: TextStyle(color: Colors.grey.shade700))
                        else
                          ..._requirements.map(_requirementTile),
                        const SizedBox(height: 24),
                        const Text('Service Session History', style: TextStyle(fontWeight: FontWeight.w900, color: blueDark)),
                        const SizedBox(height: 8),
                        if (_sessions.isEmpty)
                          Text('No recorded sessions.', style: TextStyle(color: Colors.grey.shade700))
                        else
                          ..._sessions.map(_sessionTile),
                      ],
                    ),
        ),
      ),
    );
  }
}