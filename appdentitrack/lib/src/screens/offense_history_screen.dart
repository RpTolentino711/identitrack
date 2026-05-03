import 'package:flutter/material.dart';
import 'services/offense_api.dart';
import 'offense_detail_screen.dart';

class OffenseHistoryScreen extends StatelessWidget {
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

  static const blue = Color(0xFF193B8C);
  static const blueDark = Color(0xFF102B6B);

  Color _levelColor(String lvl) {
    return lvl == 'MAJOR' ? const Color(0xFFC62828) : const Color(0xFF2E7D32);
  }

  IconData _levelIcon(String lvl) {
    return lvl == 'MAJOR' ? Icons.warning_amber_rounded : Icons.info_outline_rounded;
  }

  Widget _offenseTile(BuildContext context, OffenseItem o) {
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
              studentName: studentName,
              studentId: studentId,
              program: program,
              yearLevel: yearLevel,
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
    // Sort chronological: oldest to newest
    final sortedOffenses = List<OffenseItem>.from(allOffenses)
      ..sort((a, b) => a.dateCommitted.compareTo(b.dateCommitted));

    return Scaffold(
      backgroundColor: blue,
      appBar: AppBar(
        backgroundColor: blue,
        foregroundColor: Colors.white,
        elevation: 0,
        title: const Text(
          'Offense History',
          style: TextStyle(fontWeight: FontWeight.w900),
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
          child: sortedOffenses.isEmpty
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
                    return _offenseTile(context, sortedOffenses[index]);
                  },
                ),
        ),
      ),
    );
  }
}
