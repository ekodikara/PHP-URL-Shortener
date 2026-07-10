output "public_ip" {
  description = "Elastic IP — point your DNS A record here (or set route53_zone_id)"
  value       = aws_eip.web.public_ip
}

output "instance_id" {
  value = aws_instance.web.id
}

output "backup_bucket" {
  value = aws_s3_bucket.backups.bucket
}

output "ssm_connect" {
  description = "Open a shell without SSH"
  value       = "aws ssm start-session --target ${aws_instance.web.id} --region ${var.region}"
}

output "set_secret_hint" {
  description = "How to fill a secret (repeat per key under /<name>/)"
  value       = "aws ssm put-parameter --overwrite --type SecureString --region ${var.region} --name /${var.name}/DB_PASSWORD --value '...'"
}
