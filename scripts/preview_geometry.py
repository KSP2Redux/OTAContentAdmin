"""Identify nonvisual helper geometry in exported Unity and FBX hierarchies."""
import re


def is_helper(name):
    return bool(re.search(r"(?:^|[_.\s-])(?:col|collider|collision|reentry)(?:$|[_.\s-]|\d)", name, re.I))
