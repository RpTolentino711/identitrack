import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'services/offense_api.dart';
import 'services/alerts_api.dart';
import 'offense_detail_screen.dart';
import 'package:url_launcher/url_launcher.dart';

class OffenseHistoryScreen extends StatefulWidget {
  final String studentName;
  final String studentId;
  final String program;
  final int yearLevel;
  final List<OffenseItem> allOffenses;

  const OffenseHistoryScreen({
    super.key,
    required this.studentName,
    required this.studentId,
    required this.program,
    required this.yearLevel,
    required this.allOffenses,
  });

  @override
  State<OffenseHistoryScreen> createState() => _OffenseHistoryScreenState();
}

class _OffenseHistoryScreenState extends State<OffenseHistoryScreen> {
  final _alertsApi = AlertsApi();
  List<StudentAlert> _dismissedAlerts = [];
  bool _loadingAlerts = true;
  String? _alertsError;

  static const blue = Color(0xFF193B8C);
  static const blueDark = Color(0xFF102B6B);

  @override
  void initState() {
    super.initState();
    _loadDismissedAlerts();
  }

  Future<void> _loadDismissedAlerts() async {
    setState(() {
      _loadingAlerts = true;
      _alertsError = null;
    });

    try {
      final prefs = await SharedPreferences.getInstance();
      final dismissedSet = (prefs.getStringList('dismissed_alerts') ?? []).toSet();

      final allAlerts = await _alertsApi.getAlerts(widget.studentId);
      setState(() {
        _dismissedAlerts = allAlerts
            .where((a) => dismissedSet.contains('${a.alertType}_${a.createdAt}'))
            .toList();
        _loadingAlerts = false;
      });
    } catch (e) {
      setState(() {
        _alertsError = 'Failed to load history: $e';
        _loadingAlerts = false;
      });
    }
  }

  Color _levelColor(String lvl) {
    return lvl == 'MAJOR' ? const Color(0xFFC62828) : const Color(0xFF2E7D32);
  }

  IconData _levelIcon(String lvl) {
    return lvl == 'MAJOR' ? Icons.warning_amber_rounded : Icons.info_outline_rounded;
  }

  String _getOrdinal(int number) {
    if (number <= 0) return '$number';
    switch (number % 100) {
      case 11:
      case 12:
      case 13:
        return '${number}th';
    }
    switch (number % 10) {
      case 1:
        return '${number}st';
      case 2:
        return '${number}nd';
      case 3:
        return '${number}rd';
      default:
        return '${number}th';
    }
  }

  Widget _offenseTile(BuildContext context, OffenseItem o, String sequenceLabel) {
    final level = o.level.toUpperCase();
    final isBundle = o.isBundle;
    
    final color = isBundle ? const Color(0xFFC62828) : (level == 'MAJOR' ? const Color(0xFFD32F2F) : const Color(0xFF2E7D32));
    final bgColor = isBundle ? const Color(0xFFFFEBEE) : Colors.white;
    final borderColor = isBundle ? const Color(0xFFEF9A9A) : Colors.grey.shade200;

    return InkWell(
      onTap: () {
        Navigator.of(context).push(
          MaterialPageRoute(
            builder: (_) => OffenseDetailScreen(
              studentName: widget.studentName,
              studentId: widget.studentId,
              program: widget.program,
              yearLevel: widget.yearLevel,
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
              child: Icon(isBundle ? Icons.gavel_rounded : _levelIcon(level), color: color),
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
                    '$sequenceLabel • ${o.dateCommitted} • ${o.status}',
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
                  if (o.isDeletedByStudent) ...[
                    const SizedBox(height: 6),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                      decoration: BoxDecoration(
                        color: Colors.red.shade50,
                        borderRadius: BorderRadius.circular(6),
                        border: Border.all(color: Colors.red.shade200),
                      ),
                      child: Text(
                        'Soft Deleted / Hidden',
                        style: TextStyle(
                          fontSize: 10,
                          fontWeight: FontWeight.w800,
                          color: Colors.red.shade800,
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

  Widget _alertCard(StudentAlert alert) {
    final metadata = alert.metadata;
    final int? offenseId = metadata != null && metadata['offense_id'] != null
        ? int.tryParse(metadata['offense_id'].toString())
        : null;

    final cardContent = Padding(
      padding: const EdgeInsets.all(14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Container(
                width: 36,
                height: 36,
                decoration: BoxDecoration(
                  color: alert.badgeColor.withValues(alpha: 0.12),
                  borderRadius: BorderRadius.circular(10),
                ),
                child: Icon(
                  alert.badgeIcon,
                  color: alert.badgeColor,
                  size: 20,
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      alert.title,
                      style: const TextStyle(
                        fontWeight: FontWeight.w900,
                        color: blueDark,
                        fontSize: 14,
                      ),
                    ),
                    const SizedBox(height: 3),
                    Text(
                      alert.createdAt,
                      style: TextStyle(
                        fontSize: 11,
                        color: Colors.grey.shade500,
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),
          Text(
            alert.message,
            style: TextStyle(
              fontSize: 13,
              height: 1.4,
              color: Colors.grey.shade800,
            ),
            maxLines: 3,
            overflow: TextOverflow.ellipsis,
          ),
          if (alert.alertType == 'HEARING_SCHEDULE' || alert.alertType == 'HEARING_REMINDER')
            _buildHearingActions(alert),
          if (offenseId != null) ...[
            const SizedBox(height: 10),
            Row(
              mainAxisAlignment: MainAxisAlignment.end,
              children: [
                Text(
                  'Tap to view offense details',
                  style: TextStyle(
                    fontSize: 11,
                    color: alert.badgeColor,
                    fontWeight: FontWeight.w800,
                  ),
                ),
                const SizedBox(width: 4),
                Icon(
                  Icons.chevron_right_rounded,
                  size: 14,
                  color: alert.badgeColor,
                ),
              ],
            ),
          ],
        ],
      ),
    );

    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      decoration: BoxDecoration(
        color: Colors.white,
        border: Border(
          left: BorderSide(color: alert.badgeColor, width: 4),
          top: BorderSide(color: Colors.grey.shade200),
          right: BorderSide(color: Colors.grey.shade200),
          bottom: BorderSide(color: Colors.grey.shade200),
        ),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.05),
            blurRadius: 8,
            offset: const Offset(0, 2),
          ),
        ],
      ),
      child: offenseId != null
          ? Material(
              color: Colors.transparent,
              child: InkWell(
                onTap: () async {
                  showDialog(
                    context: context,
                    barrierDismissible: false,
                    builder: (context) => const Center(
                      child: CircularProgressIndicator(),
                    ),
                  );
                  try {
                    final res = await OffenseApi().getOffenses(studentId: widget.studentId);
                    if (!mounted) return;
                    Navigator.of(context).pop(); // dismiss loading dialog
                    final offense = res.items.firstWhere(
                      (o) => o.offenseId == offenseId,
                    );
                    await Navigator.of(context).push(
                      MaterialPageRoute(
                        builder: (_) => OffenseDetailScreen(
                          offense: offense,
                          studentId: widget.studentId,
                          studentName: widget.studentName,
                          program: res.program,
                          yearLevel: res.yearLevel,
                        ),
                      ),
                    );
                  } catch (e) {
                    if (mounted) {
                      Navigator.of(context).pop(); // dismiss loading dialog
                      ScaffoldMessenger.of(context).showSnackBar(
                        const SnackBar(content: Text('Offense details not found or deleted.')),
                      );
                    }
                  }
                },
                child: cardContent,
              ),
            )
          : cardContent,
    );
  }

  Widget _buildHearingActions(StudentAlert alert) {
    final meta = alert.metadata;
    if (meta == null) return const SizedBox.shrink();

    final isOnline = meta['hearing_type'] == 'ONLINE';
    final locationOrLink = meta['hearing_link_or_location']?.toString() ?? '';

    return Padding(
      padding: const EdgeInsets.only(top: 12),
      child: Row(
        children: [
          Expanded(
            child: ElevatedButton.icon(
              onPressed: () async {
                if (isOnline && locationOrLink.isNotEmpty) {
                  final uri = Uri.tryParse(locationOrLink);
                  if (uri != null && await canLaunchUrl(uri)) {
                    await launchUrl(uri, mode: LaunchMode.externalApplication);
                  } else {
                    if (mounted) {
                      ScaffoldMessenger.of(context).showSnackBar(
                        const SnackBar(content: Text('Invalid or empty meeting link.')),
                      );
                    }
                  }
                } else {
                  if (mounted) {
                    showDialog(
                      context: context,
                      builder: (c) => AlertDialog(
                        title: const Text('Hearing Location'),
                        content: Text(locationOrLink.isNotEmpty ? locationOrLink : 'Wait for instructions from Admin.'),
                        actions: [
                          TextButton(
                            onPressed: () => Navigator.pop(c),
                            child: const Text('OK'),
                          ),
                        ],
                      ),
                    );
                  }
                }
              },
              icon: Icon(isOnline ? Icons.video_camera_front_rounded : Icons.location_on_rounded, size: 18),
              label: Text(isOnline ? 'Join Online' : 'View Location'),
              style: ElevatedButton.styleFrom(
                backgroundColor: isOnline ? blueDark : Colors.amber.shade800,
                foregroundColor: Colors.white,
                padding: const EdgeInsets.symmetric(vertical: 8),
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
              ),
            ),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: OutlinedButton.icon(
              onPressed: () {
                showDialog(
                  context: context,
                  builder: (c) => AlertDialog(
                    title: const Text('Decline Hearing'),
                    content: const Text('To decline this hearing or request a reschedule, please contact the UPCC Administrator directly.'),
                    actions: [
                      TextButton(
                        onPressed: () => Navigator.pop(c),
                        child: const Text('OK'),
                      ),
                    ],
                  ),
                );
              },
              icon: const Icon(Icons.cancel_rounded, size: 18),
              label: const Text('Decline'),
              style: OutlinedButton.styleFrom(
                foregroundColor: Colors.red.shade700,
                side: BorderSide(color: Colors.red.shade200),
                padding: const EdgeInsets.symmetric(vertical: 8),
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
              ),
            ),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    // Sort chronological: oldest to newest
    final sortedOffenses = List<OffenseItem>.from(widget.allOffenses)
      ..sort((a, b) => a.dateCommitted.compareTo(b.dateCommitted));

    int minorCount = 0;
    int majorCount = 0;
    final Map<int, String> offenseLabels = {};
    for (final o in sortedOffenses) {
      final lvl = o.level.toUpperCase();
      if (lvl == 'MINOR') {
        minorCount++;
        offenseLabels[o.offenseId] = '${_getOrdinal(minorCount)} Minor Offense';
      } else if (lvl == 'MAJOR') {
        majorCount++;
        offenseLabels[o.offenseId] = '${_getOrdinal(majorCount)} Major Offense';
      } else {
        offenseLabels[o.offenseId] = o.level;
      }
    }

    return DefaultTabController(
      length: 2,
      child: Scaffold(
        backgroundColor: blue,
        appBar: AppBar(
          backgroundColor: blue,
          foregroundColor: Colors.white,
          elevation: 0,
          title: const Text(
            'History Logs',
            style: TextStyle(fontWeight: FontWeight.w900),
          ),
          bottom: const TabBar(
            indicatorColor: Colors.white,
            labelColor: Colors.white,
            unselectedLabelColor: Colors.white70,
            labelStyle: TextStyle(fontWeight: FontWeight.w900, fontSize: 14),
            tabs: [
              Tab(text: 'Offenses'),
              Tab(text: 'Notifications'),
            ],
          ),
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
            child: TabBarView(
              children: [
                // Tab 1: Offenses
                sortedOffenses.isEmpty
                    ? Center(
                        child: Container(
                          padding: const EdgeInsets.all(14),
                          decoration: BoxDecoration(
                            color: Colors.white,
                            borderRadius: BorderRadius.circular(16),
                            border: Border.all(color: Colors.grey.shade200),
                          ),
                          child: Text(
                            'No offense history found.',
                            style: TextStyle(
                              color: Colors.grey.shade700,
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                        ),
                      )
                    : ListView.builder(
                        padding: const EdgeInsets.all(18),
                        itemCount: sortedOffenses.length,
                        itemBuilder: (context, index) {
                          final o = sortedOffenses[index];
                          final label = offenseLabels[o.offenseId] ?? o.level;
                          return _offenseTile(context, o, label);
                        },
                      ),

                // Tab 2: Dismissed Notifications
                _loadingAlerts
                    ? const Center(child: CircularProgressIndicator())
                    : _alertsError != null
                        ? Center(
                            child: Padding(
                              padding: const EdgeInsets.all(18),
                              child: Column(
                                mainAxisSize: MainAxisSize.min,
                                children: [
                                  Text(_alertsError!, textAlign: TextAlign.center),
                                  const SizedBox(height: 10),
                                  ElevatedButton(
                                    onPressed: _loadDismissedAlerts,
                                    child: const Text('Retry'),
                                  ),
                                ],
                              ),
                            ),
                          )
                        : _dismissedAlerts.isEmpty
                            ? Center(
                                child: Container(
                                  padding: const EdgeInsets.all(14),
                                  decoration: BoxDecoration(
                                    color: Colors.white,
                                    borderRadius: BorderRadius.circular(16),
                                    border: Border.all(color: Colors.grey.shade200),
                                  ),
                                  child: Text(
                                    'No dismissed alerts found.',
                                    style: TextStyle(
                                      color: Colors.grey.shade700,
                                      fontWeight: FontWeight.w700,
                                    ),
                                  ),
                                ),
                              )
                            : ListView.builder(
                                padding: const EdgeInsets.all(18),
                                itemCount: _dismissedAlerts.length,
                                itemBuilder: (context, index) {
                                  final alert = _dismissedAlerts[index];
                                  return _alertCard(alert);
                                },
                              ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
