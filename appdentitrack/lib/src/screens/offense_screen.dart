import 'dart:async';

import 'package:flutter/material.dart';
import 'services/offense_api.dart';
import 'offense_detail_screen.dart';
import 'dashboard_screen.dart';
import 'service_history_screen.dart';
import 'alert_screen.dart';
import 'profile_screen.dart';
import 'offense_history_screen.dart';
import 'shared_bottom_nav.dart';

class OffenseScreen extends StatefulWidget {
  final String studentId;
  final String studentName; // optional fallback from login

  const OffenseScreen({
    super.key,
    required this.studentId,
    required this.studentName,
  });

  @override
  State<OffenseScreen> createState() => _OffenseScreenState();
}

class _OffenseScreenState extends State<OffenseScreen> {
  final _api = OffenseApi();
  Timer? _refreshTimer;

  bool _loading = true;
  String? _error;

  int _total = 0;
  int _minor = 0;
  int _major = 0;

  String _studentName = '';
  String _program = '';
  int _yearLevel = 0;

  List<OffenseItem> _items = [];
  String _filter = 'ALL'; // ALL, MINOR, MAJOR

  static const blue = Color(0xFF193B8C);
  static const blueDark = Color(0xFF102B6B);

  @override
  void initState() {
    super.initState();
    _studentName = widget.studentName.trim();
    _load();
    _refreshTimer = Timer.periodic(const Duration(seconds: 10), (_) {
      if (mounted && !_loading) {
        _load();
      }
    });
  }

  @override
  void dispose() {
    _refreshTimer?.cancel();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });

    try {
      final res = await _api.getOffenses(studentId: widget.studentId);
      if (!mounted) return;

      setState(() {
        _total = res.total;
        _minor = res.minor;
        _major = res.major;

        _studentName = res.studentName.trim().isEmpty
            ? _studentName
            : res.studentName.trim();
        _program = res.program;
        _yearLevel = res.yearLevel;

        _items = res.items;
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

  Future<void> _hardDeleteOffense(OffenseItem o) async {
    final confirm = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Row(
          children: [
            Icon(Icons.warning_amber_rounded, color: Colors.red),
            SizedBox(width: 10),
            Text('Hard Delete', style: TextStyle(fontWeight: FontWeight.w900)),
          ],
        ),
        content: const Text('Are you sure you want to PERMANENTLY delete this offense? This will wipe all associated records (appeals, cases, etc.) and cannot be undone.'),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('Cancel', style: TextStyle(color: Colors.grey, fontWeight: FontWeight.w700)),
          ),
          ElevatedButton(
            onPressed: () => Navigator.of(context).pop(true),
            style: ElevatedButton.styleFrom(
              backgroundColor: Colors.red,
              foregroundColor: Colors.white,
              elevation: 0,
            ),
            child: const Text('PERMANENT DELETE', style: TextStyle(fontWeight: FontWeight.w900)),
          ),
        ],
      ),
    );

    if (confirm != true) return;

    setState(() => _loading = true);
    try {
      await _api.deleteOffense(studentId: widget.studentId, offenseId: o.offenseId);
      await _load();
    } catch (e) {
      if (!mounted) return;
      setState(() => _loading = false);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Failed to delete offense: $e')),
      );
    }
  }

  String _displayName() {
    final n = _studentName.trim();
    return n.isEmpty ? 'Student' : n;
  }

  String _greeting() {
    final hour = DateTime.now().hour;
    if (hour < 12) return 'Good morning';
    if (hour < 18) return 'Good afternoon';
    return 'Good evening';
  }

  Color _levelColor(String level) {
    switch (level.toUpperCase()) {
      case 'UPCC DECISION':
        return const Color(0xFF673AB7); // Deep Purple
      case 'MAJOR':
        return const Color(0xFFD32F2F);
      case 'MINOR':
      default:
        return const Color(0xFF2E7D32);
    }
  }

  IconData _levelIcon(String level) {
    switch (level.toUpperCase()) {
      case 'UPCC DECISION':
        return Icons.gavel_rounded;
      case 'MAJOR':
        return Icons.warning_amber_rounded;
      case 'MINOR':
      default:
        return Icons.report_gmailerrorred_rounded;
    }
  }


  Widget _statRow() {
    Widget statChip(String label, String value, {Color? color}) {
      final c = color ?? blueDark;
      return Container(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
        decoration: BoxDecoration(
          color: c.withValues(alpha: 0.10),
          borderRadius: BorderRadius.circular(999),
          border: Border.all(color: c.withValues(alpha: 0.25)),
        ),
        child: Text(
          '$label: $value',
          style: TextStyle(
            color: c,
            fontWeight: FontWeight.w900,
            fontSize: 12.5,
          ),
        ),
      );
    }

    return Wrap(
      spacing: 8,
      runSpacing: 8,
      children: [
        statChip('Total', _total.toString()),
        statChip('Minor', _minor.toString(), color: const Color(0xFFE65100)),
        statChip('Major', _major.toString(), color: const Color(0xFFD32F2F)),
      ],
    );
  }

  Widget _filterToggle() {
    return SegmentedButton<String>(
      segments: const [
        ButtonSegment<String>(
          value: 'ALL',
          label: Text('All', style: TextStyle(fontWeight: FontWeight.w700)),
        ),
        ButtonSegment<String>(
          value: 'MINOR',
          label: Text('Minor', style: TextStyle(fontWeight: FontWeight.w700)),
        ),
        ButtonSegment<String>(
          value: 'MAJOR',
          label: Text('Major', style: TextStyle(fontWeight: FontWeight.w700)),
        ),
      ],
      selected: {_filter},
      onSelectionChanged: (Set<String> newSelection) {
        setState(() {
          _filter = newSelection.first;
        });
      },
      style: SegmentedButton.styleFrom(
        selectedForegroundColor: Colors.white,
        selectedBackgroundColor: blueDark,
        visualDensity: VisualDensity.compact,
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 0),
        textStyle: const TextStyle(fontSize: 13),
      ),
    );
  }

  Widget _offenseTile(OffenseItem o) {
    final level = o.level.toUpperCase();
    final isBundle = o.isBundle;
    
    final color = isBundle ? const Color(0xFFC62828) : _levelColor(level);
    final bgColor = isBundle ? const Color(0xFFFFEBEE) : Colors.white;
    final borderColor = isBundle ? const Color(0xFFEF9A9A) : Colors.grey.shade200;

    return InkWell(
      onTap: () {
        Navigator.of(context).push(
          MaterialPageRoute(
            builder: (_) => OffenseDetailScreen(
              studentId: widget.studentId,
              studentName: _studentName,
              program: _program,
              yearLevel: _yearLevel,
              offense: o,
            ),
          ),
        );
      },
      borderRadius: BorderRadius.circular(16),
      child: Container(
        margin: const EdgeInsets.only(bottom: 12),
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: bgColor,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: borderColor),
        ),
        child: Row(
          children: [
            Container(
              width: 44,
              height: 44,
              decoration: BoxDecoration(
                color: color.withValues(alpha: 0.12),
                borderRadius: BorderRadius.circular(12),
              ),
              child: Icon(_levelIcon(level), color: color),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    o.title.trim(),
                    style: const TextStyle(
                      fontWeight: FontWeight.w900,
                      color: blueDark,
                    ),
                  ),
                  const SizedBox(height: 6),
                  Text(
                    '$level • ${o.dateCommitted} • ${o.status}',
                    style: TextStyle(
                      color: Colors.grey.shade700,
                      fontWeight: FontWeight.w600,
                      fontSize: 12.5,
                    ),
                  ),
                  if (o.description.trim().isNotEmpty) ...[
                    const SizedBox(height: 6),
                    Text(
                      o.description.trim(),
                      style: TextStyle(
                        color: isBundle ? const Color(0xFFC62828) : Colors.grey.shade700,
                        height: 1.4,
                      ),
                    ),
                  ],
                  if (o.appealStatus.isNotEmpty) ...[
                    const SizedBox(height: 8),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                      decoration: BoxDecoration(
                        color: o.appealStatus == 'APPROVED' ? Colors.green.shade50 : Colors.red.shade50,
                        borderRadius: BorderRadius.circular(6),
                        border: Border.all(
                          color: o.appealStatus == 'APPROVED' ? Colors.green.shade300 : Colors.red.shade300,
                        ),
                      ),
                      child: Text(
                        'Appeal Status: ${o.appealStatus}',
                        style: TextStyle(
                          fontSize: 11,
                          fontWeight: FontWeight.w800,
                          color: o.appealStatus == 'APPROVED' ? Colors.green.shade800 : Colors.red.shade800,
                        ),
                      ),
                    ),
                  ],
                ],
              ),
            ),
            const Icon(Icons.chevron_right_rounded, color: Colors.grey),
          ],
        ),
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
        title: const Text(
          'Offenses',
          style: TextStyle(fontWeight: FontWeight.w900),
        ),
        actions: [
          IconButton(
            tooltip: 'History',
            onPressed: () {
              Navigator.of(context).push(
                MaterialPageRoute(
                  builder: (_) => OffenseHistoryScreen(
                    studentName: _studentName,
                    studentId: widget.studentId,
                    program: _program,
                    yearLevel: _yearLevel,
                    allOffenses: _items,
                  ),
                ),
              );
            },
            icon: const Icon(Icons.history_rounded),
          ),
        ],
      ),
      body: SafeArea(
        child: Container(
          width: double.infinity,
          decoration: const BoxDecoration(
            color: Color(0xFFF5F6FB),
            borderRadius: BorderRadius.only(
              topLeft: Radius.circular(28),
              topRight: Radius.circular(28),
            ),
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
              : ListView(
                  padding: const EdgeInsets.fromLTRB(18, 18, 18, 90),
                  children: [
                    const Text(
                      'All Recorded Violations',
                      style: TextStyle(
                        fontSize: 20,
                        fontWeight: FontWeight.w900,
                        color: blueDark,
                      ),
                    ),
                    const SizedBox(height: 6),
                    Text(
                      '${_greeting()}, ${_displayName()}',
                      style: TextStyle(
                        color: Colors.grey.shade700,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      'Student ID: ${widget.studentId}',
                      style: TextStyle(
                        color: Colors.grey.shade700,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                    const SizedBox(height: 14),
                    _statRow(),
                    const SizedBox(height: 14),
                    Align(
                      alignment: Alignment.centerRight,
                      child: _filterToggle(),
                    ),
                    const SizedBox(height: 14),

                    if (_items.where((o) => !o.isDeletedByStudent).isEmpty)
                      Container(
                        padding: const EdgeInsets.all(14),
                        decoration: BoxDecoration(
                          color: Colors.white,
                          borderRadius: BorderRadius.circular(16),
                          border: Border.all(color: Colors.grey.shade200),
                        ),
                        child: Text(
                          'No offenses found.',
                          style: TextStyle(
                            color: Colors.grey.shade700,
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                      )
                    else ...[
                      ..._items.where((o) {
                        if (o.isDeletedByStudent) return false;
                        if (_filter == 'ALL') return true;
                        return o.level.toUpperCase() == _filter;
                      }).map(_offenseTile).toList(),
                      if (_items.where((o) {
                        if (o.isDeletedByStudent) return false;
                        if (_filter == 'ALL') return true;
                        return o.level.toUpperCase() == _filter;
                      }).isEmpty)
                        Container(
                          padding: const EdgeInsets.all(14),
                          decoration: BoxDecoration(
                            color: Colors.white,
                            borderRadius: BorderRadius.circular(16),
                            border: Border.all(color: Colors.grey.shade200),
                          ),
                          child: Text(
                            'No $_filter offenses found.',
                            style: TextStyle(
                              color: Colors.grey.shade700,
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                        )
                    ],
                  ],
                ),
        ),
      ),
      bottomNavigationBar: SharedBottomNav(
        currentIndex: 1,
        studentId: widget.studentId,
        studentName: widget.studentName,
      ),
    );
  }
}
