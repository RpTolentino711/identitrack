import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'services/alerts_api.dart';
import 'shared_bottom_nav.dart';

class AlertsScreen extends StatefulWidget {
  final String studentId;
  final String studentName;

  const AlertsScreen({
    super.key,
    required this.studentId,
    required this.studentName,
  });

  @override
  State<AlertsScreen> createState() => _AlertsScreenState();
}

class _AlertsScreenState extends State<AlertsScreen> {
  final _api = AlertsApi();
  bool _loading = true;
  String? _error;
  List<StudentAlert> _alerts = [];
  List<String> _dismissedAlerts = [];

  static const blue = Color(0xFF193B8C);
  static const blueDark = Color(0xFF102B6B);

  @override
  void initState() {
    super.initState();
    _load();
    // Auto-refresh every 10 seconds
    Future.doWhile(() async {
      await Future.delayed(const Duration(seconds: 10));
      if (mounted) {
        await _load();
      }
      return mounted;
    });
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });

    try {
      final alerts = await _api.getAlerts(widget.studentId);
      final prefs = await SharedPreferences.getInstance();
      _dismissedAlerts = prefs.getStringList('dismissed_alerts') ?? [];

      if (!mounted) return;

      setState(() {
        _alerts = alerts.where((a) {
          final id = '${a.alertType}_${a.createdAt}';
          return !_dismissedAlerts.contains(id);
        }).toList();
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
      final now = DateTime.now();
      final diff = now.difference(dt);

      if (diff.inDays == 0) {
        if (diff.inHours == 0) {
          return '${diff.inMinutes} minutes ago';
        }
        return '${diff.inHours} hours ago';
      } else if (diff.inDays == 1) {
        return 'Yesterday';
      } else if (diff.inDays < 7) {
        return '${diff.inDays} days ago';
      }
      return '${dt.day}/${dt.month}/${dt.year}';
    } catch (_) {
      return dateStr;
    }
  }

  Widget _alertCard(StudentAlert alert) {
    return Container(
      margin: const EdgeInsets.only(bottom: 14),
      padding: const EdgeInsets.all(14),
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
          )
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                width: 40,
                height: 40,
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
                      style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 14, color: blueDark),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                    Text(
                      _formatDate(alert.createdAt),
                      style: TextStyle(fontSize: 11, color: Colors.grey.shade500),
                    ),
                  ],
                ),
              ),
              InkWell(
                onTap: () async {
                  final id = '${alert.alertType}_${alert.createdAt}';
                  final prefs = await SharedPreferences.getInstance();
                  final current = prefs.getStringList('dismissed_alerts') ?? [];
                  if (!current.contains(id)) {
                    current.add(id);
                    await prefs.setStringList('dismissed_alerts', current);
                  }
                  setState(() {
                    _dismissedAlerts.add(id);
                    _alerts.remove(alert);
                  });
                },
                borderRadius: BorderRadius.circular(20),
                child: Padding(
                  padding: const EdgeInsets.all(8.0),
                  child: Icon(Icons.close_rounded, size: 20, color: Colors.grey.shade400),
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),
          Text(
            alert.message,
            style: TextStyle(fontSize: 13, height: 1.4, color: Colors.grey.shade800),
            maxLines: 3,
            overflow: TextOverflow.ellipsis,
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
        title: const Text('Alerts', style: TextStyle(fontWeight: FontWeight.w900)),
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
                  : _alerts.isEmpty
                      ? Center(
                          child: Padding(
                            padding: const EdgeInsets.all(28),
                            child: Column(
                              mainAxisAlignment: MainAxisAlignment.center,
                              children: [
                                Icon(Icons.notifications_none_rounded, size: 64, color: Colors.grey.shade300),
                                const SizedBox(height: 16),
                                Text(
                                  'No alerts yet',
                                  style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700, color: blueDark),
                                ),
                                const SizedBox(height: 8),
                                Text(
                                  'You\'re all caught up!',
                                  style: TextStyle(color: Colors.grey.shade600),
                                ),
                              ],
                            ),
                          ),
                        )
                      : ListView(
                          padding: const EdgeInsets.fromLTRB(18, 18, 18, 18),
                          children: [
                            Text(
                              'Notifications',
                              style: TextStyle(color: Colors.grey.shade700, fontWeight: FontWeight.w900, fontSize: 16),
                            ),
                            const SizedBox(height: 12),
                      ..._alerts.map((alert) => _alertCard(alert)),
                          ],
                        ),
        ),
      ),
      bottomNavigationBar: SharedBottomNav(
        currentIndex: 3,
        studentId: widget.studentId,
        studentName: widget.studentName,
      ),
    );
  }
}
